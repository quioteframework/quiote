<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\EnvPlaceholder;
use Quiote\Exception\ConfigurationException;
use Quiote\Support\Environment\Environment;
use Quiote\Support\Environment\EnvironmentReaderInterface;

/**
 * `%env(NAME)%` in a configuration value: what a deployment can decide without recompiling
 * anything. The placeholder survives compilation and is resolved when the artifact is loaded, so
 * this covers both halves -- recognizing one, and turning it into a value of the right type.
 */
class EnvPlaceholderTest extends TestCase
{
	protected function tearDown(): void
	{
		Environment::useEnvironmentReader(null);
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $variables
	 */
	private function environment(array $variables): void
	{
		Environment::useEnvironmentReader(new class ($variables) implements EnvironmentReaderInterface {
			/** @param array<string, string> $variables */
			public function __construct(private readonly array $variables)
			{
			}

			public function get(string $name): string|false
			{
				return $this->variables[$name] ?? false;
			}
		});
	}

	public function testAValueWithNoPlaceholderIsNotAPlaceholder(): void
	{
		$this->assertFalse(EnvPlaceholder::contains('plain'));
		$this->assertFalse(EnvPlaceholder::contains('%core.app_dir%/cache'));
		$this->assertFalse(EnvPlaceholder::contains(['a' => ['b' => 42]]));
		$this->assertFalse(EnvPlaceholder::contains(null));
	}

	public function testAPlaceholderIsFoundAtAnyDepth(): void
	{
		$this->assertTrue(EnvPlaceholder::contains('%env(FOO)%'));
		$this->assertTrue(EnvPlaceholder::contains('https://%env(HOST)%/v1'));
		$this->assertTrue(EnvPlaceholder::contains(['a' => ['b' => ['%env(FOO)%']]]));
	}

	/**
	 * A malformed placeholder must not read as "no placeholder here": that would cache and apply it
	 * as a literal string instead of reporting the typo.
	 */
	public function testAMalformedPlaceholderStillCountsAsOne(): void
	{
		$this->assertTrue(EnvPlaceholder::contains('%env(not-a-name)%'));
	}

	public function testAWholeValuePlaceholderTakesItsTypeFromTheEnvironment(): void
	{
		$this->environment([
			'FLAG_ON' => 'true',
			'FLAG_OFF' => 'off',
			'COUNT' => '42',
			'RATE' => '0.25',
			'NAME' => 'sessions/',
		]);

		$this->assertTrue(EnvPlaceholder::resolve('%env(FLAG_ON)%'));
		$this->assertFalse(EnvPlaceholder::resolve('%env(FLAG_OFF)%'));
		$this->assertSame(42, EnvPlaceholder::resolve('%env(COUNT)%'));
		$this->assertSame(0.25, EnvPlaceholder::resolve('%env(RATE)%'));
		$this->assertSame('sessions/', EnvPlaceholder::resolve('%env(NAME)%'));
	}

	/**
	 * A mounted secret or a `kubectl set env` value routinely carries a trailing newline, and a
	 * setting that has to be a bool cannot afford to become the string "true\n".
	 */
	public function testSurroundingWhitespaceDoesNotChangeWhatTheValueMeans(): void
	{
		$this->environment(['FLAG' => "true\n", 'SECRET' => "  s3cr3t\n"]);

		$this->assertTrue(EnvPlaceholder::resolve('%env(FLAG)%'));
		$this->assertSame('s3cr3t', EnvPlaceholder::resolve('%env(SECRET)%'));
	}

	public function testWhitespaceInsideThePlaceholderIsAllowed(): void
	{
		$this->environment(['FLAG' => 'yes']);

		$this->assertTrue(EnvPlaceholder::resolve('%env( FLAG )%'));
	}

	public function testAnEmptyVariableResolvesToNullTheWayAnEmptyConfigValueDoes(): void
	{
		$this->environment(['EMPTY' => '']);

		$this->assertNull(EnvPlaceholder::resolve('%env(EMPTY)%'));
	}

	public function testAnEmbeddedPlaceholderIsSubstitutedAndStaysAString(): void
	{
		$this->environment(['HOST' => 'api.internal', 'PORT' => '8443']);

		$this->assertSame(
			'https://api.internal:8443/v1',
			EnvPlaceholder::resolve('https://%env(HOST)%:%env(PORT)%/v1')
		);
	}

	public function testAFallbackIsUsedOnlyWhenTheVariableIsUnset(): void
	{
		$this->environment(['SET' => 'from-env']);

		$this->assertSame('from-env', EnvPlaceholder::resolve('%env(SET, fallback)%'));
		$this->assertSame('fallback', EnvPlaceholder::resolve('%env(UNSET, fallback)%'));
		$this->assertSame('never', EnvPlaceholder::resolve('%env(UNSET,never)%'));
	}

	public function testAFallbackIsLiteralizedTheSameWayAVariableIs(): void
	{
		$this->environment([]);

		$this->assertFalse(EnvPlaceholder::resolve('%env(UNSET, false)%'));
		$this->assertSame(14, EnvPlaceholder::resolve('%env(UNSET, 14)%'));
	}

	/**
	 * A present-but-empty fallback is a fallback: "empty unless the deployment says otherwise" is a
	 * legitimate thing to configure, and it must not be mistaken for having no fallback at all.
	 */
	public function testAnEmptyFallbackIsStillAFallback(): void
	{
		$this->environment([]);

		$this->assertNull(EnvPlaceholder::resolve('%env(UNSET,)%'));
	}

	public function testAValueIsResolvedAtEveryDepthWhileKeysAreLeftAlone(): void
	{
		$this->environment(['FLAG' => 'true', 'PATH' => 'var/cassettes']);

		$this->assertSame(
			['%env(FLAG)%' => ['replay.enabled' => true, 'replay.store.path' => 'var/cassettes'], 'n' => 7],
			EnvPlaceholder::resolve([
				'%env(FLAG)%' => ['replay.enabled' => '%env(FLAG)%', 'replay.store.path' => '%env(PATH)%'],
				'n' => 7,
			])
		);
	}

	public function testANonStringScalarIsReturnedUnchanged(): void
	{
		$this->assertSame(7, EnvPlaceholder::resolve(7));
		$this->assertTrue(EnvPlaceholder::resolve(true));
		$this->assertNull(EnvPlaceholder::resolve(null));
	}

	/**
	 * The resolved text is deployment input, not configuration: re-expanding it would make what a
	 * setting means depend on whether a variable happens to hold a directive reference.
	 */
	public function testAResolvedValueIsNotExpandedFurther(): void
	{
		$this->environment(['TRICK' => '%core.app_dir%', 'NESTED' => '%env(TRICK)%']);

		$this->assertSame('%core.app_dir%', EnvPlaceholder::resolve('%env(TRICK)%'));
		$this->assertSame('%env(TRICK)%', EnvPlaceholder::resolve('%env(NESTED)%'));
	}

	public function testAnUnsetVariableWithNoFallbackNamesTheVariableAndTheFile(): void
	{
		$this->environment([]);

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('"REPLAY_ENABLED"');
		$this->expectExceptionMessage('config/settings.xml');

		EnvPlaceholder::resolve('%env(REPLAY_ENABLED)%', 'config/settings.xml');
	}

	public function testAnUnsetVariableInsideANestedValueIsReported(): void
	{
		$this->environment([]);

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('"DEEP"');

		EnvPlaceholder::resolve(['a' => ['b' => 'prefix-%env(DEEP)%']], 'config/settings.xml');
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformedProvider(): array
	{
		return [
			'hyphen in the name' => ['%env(not-a-name)%'],
			'name starting with a digit' => ['%env(1ST)%'],
			'empty name' => ['%env()%'],
			'unclosed' => ['%env(NAME%'],
			'missing trailing percent' => ['%env(NAME)'],
			'embedded among valid ones' => ['%env(GOOD)%/%env(bad name)%'],
		];
	}

	#[PHPUnit\Framework\Attributes\DataProvider('malformedProvider')]
	public function testAMalformedPlaceholderIsRejectedRatherThanPassedThrough(string $value): void
	{
		$this->environment(['GOOD' => 'g', 'NAME' => 'n']);

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('malformed environment placeholder');

		EnvPlaceholder::resolve($value, 'config/settings.xml');
	}

	/**
	 * A variable whose own value contains "%env(" must not be mistaken for a malformed placeholder in
	 * the configuration file -- which is why the syntax check counts markers before substituting.
	 */
	public function testAVariableWhoseValueLooksLikeAPlaceholderIsNotASyntaxError(): void
	{
		$this->environment(['ODD' => '%env(']);

		$this->assertSame('x%env(', EnvPlaceholder::resolve('x%env(ODD)%'));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function requestControlledNameProvider(): array
	{
		return [
			'the httpoxy variable' => ['HTTP_PROXY'],
			'its lowercase spelling' => ['http_proxy'],
			'a header that looks like a setting' => ['HTTP_REPLAY_ENABLED'],
			'mixed case' => ['Http_Proxy'],
		];
	}

	/**
	 * `getenv()` answers from the SAPI's request environment as well as the process environment, and
	 * under CGI/FastCGI every request header lands there as `HTTP_<NAME>`. Reading one as
	 * configuration would let whoever sent the request supply it.
	 */
	#[PHPUnit\Framework\Attributes\DataProvider('requestControlledNameProvider')]
	public function testAVariableNameARequestCanForgeIsRefused(string $name): void
	{
		$this->environment([$name => 'attacker-supplied']);

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('a request can forge');

		EnvPlaceholder::resolve('%env(' . $name . ')%', 'config/settings.xml');
	}

	/**
	 * Refused before the read, not after: a fallback must not make the placeholder look harmless
	 * while still consulting a name the client controls.
	 */
	public function testARequestControlledNameIsRefusedEvenWithAFallback(): void
	{
		$this->environment([]);

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('a request can forge');

		EnvPlaceholder::resolve('%env(HTTP_TIMEOUT, 30)%', 'config/settings.xml');
	}

	public function testANameThatMerelyContainsHttpIsFine(): void
	{
		$this->environment(['APP_HTTP_TIMEOUT' => '30', 'MY_HTTP_HOST' => 'api.internal']);

		$this->assertSame(30, EnvPlaceholder::resolve('%env(APP_HTTP_TIMEOUT)%'));
		$this->assertSame('api.internal', EnvPlaceholder::resolve('%env(MY_HTTP_HOST)%'));
	}

	/**
	 * End to end through the real reader rather than a fake one: a dotenv bootstrap loads `.env` into
	 * `$_ENV` without calling putenv(), and a placeholder has to resolve from that exactly as it does
	 * from a variable the platform exported.
	 */
	public function testAPlaceholderResolvesFromAVariableADotenvBootstrapLoaded(): void
	{
		// No fake reader here -- tearDown()'s useEnvironmentReader(null) is already the default, so
		// this goes through SystemEnvironmentReader.
		$_ENV['QUIOTE_TEST_DOTENV_FLAG'] = 'true';

		try {
			$this->assertTrue(EnvPlaceholder::resolve('%env(QUIOTE_TEST_DOTENV_FLAG)%'));
		} finally {
			unset($_ENV['QUIOTE_TEST_DOTENV_FLAG']);
		}
	}

	public function testTheFileIsLabelledUnknownWhenTheCallerHasNoneToGive(): void
	{
		$this->environment([]);

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('(unknown)');

		EnvPlaceholder::resolve('%env(NOPE)%');
	}
}
