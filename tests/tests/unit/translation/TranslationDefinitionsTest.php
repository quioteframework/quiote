<?php

use Quiote\Config\TranslationConfigHandler;
use Quiote\Exception\ConfigurationException;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Translation\SimpleTranslator;
use Quiote\Translation\TranslationDefinitions;

/**
 * Reading a *generated* file, so the failure worth guarding against is a cache compiled by a
 * different version of the framework. Every rejection here says so, instead of letting a type error
 * or a non-callable filter surface from somewhere deep inside a translate() call.
 */
class TranslationDefinitionsTest extends PhpUnitTestCase
{
	/**
	 * @return array{
	 *   defaultDomain: string,
	 *   defaultLocale: ?string,
	 *   defaultTimeZone: ?string,
	 *   locales: array<string, array{identifier: string, identifierData: array<string, mixed>, parameters: array<string, mixed>}>,
	 *   translators: array<string, array<string, array{class: string, parameters: array<string, mixed>, filters: array<int, mixed>}>>
	 * }
	 */
	private function declaration(): array
	{
		return [
			'defaultDomain' => 'messages',
			'defaultLocale' => 'en_GB',
			'defaultTimeZone' => 'Europe/London',
			'locales' => [
				'en_GB' => [
					'identifier' => 'en_GB',
					'identifierData' => [
						'language' => 'en',
						'script' => null,
						'territory' => 'GB',
						'variant' => null,
						'options' => [],
						'locale_str' => 'en_GB',
						'option_str' => null,
					],
					'parameters' => ['fallback' => 'en'],
				],
			],
			'translators' => [
				'messages' => [
					'msg' => ['class' => SimpleTranslator::class, 'parameters' => [], 'filters' => []],
				],
			],
		];
	}

	public function testAValidDeclarationIsRead(): void
	{
		$definitions = TranslationDefinitions::fromCompiled($this->declaration());

		$this->assertSame('messages', $definitions->defaultDomain);
		$this->assertSame('en_GB', $definitions->defaultLocale);
		$this->assertSame('Europe/London', $definitions->defaultTimeZone);
		$this->assertSame('en_GB', $definitions->locales['en_GB']['identifier']);
		$this->assertSame('GB', $definitions->locales['en_GB']['identifierData']['territory']);
		$this->assertSame(['fallback' => 'en'], $definitions->locales['en_GB']['parameters']);
		$this->assertSame(
			SimpleTranslator::class,
			$definitions->translators['messages']['msg']['class'],
		);
	}

	/**
	 * A missing timezone means "adopt PHP's default", which the manager does. A missing default
	 * locale is rejected by the manager, not here -- it has a better message for it.
	 */
	public function testANullLocaleAndTimeZoneAreLegal(): void
	{
		$declaration = $this->declaration();
		$declaration['defaultLocale'] = null;
		$declaration['defaultTimeZone'] = null;

		$definitions = TranslationDefinitions::fromCompiled($declaration);

		$this->assertNull($definitions->defaultLocale);
		$this->assertNull($definitions->defaultTimeZone);
	}

	/**
	 * A cache compiled when parseLocaleIdentifier() answered a different shape would otherwise feed
	 * a half-populated array to every locale match in the process.
	 */
	public function testAnIncompleteParsedIdentifierIsFilledWithNulls(): void
	{
		$declaration = $this->declaration();
		$declaration['locales']['en_GB']['identifierData'] = ['language' => 'en'];

		$definitions = TranslationDefinitions::fromCompiled($declaration);

		$data = $definitions->locales['en_GB']['identifierData'];
		$this->assertSame('en', $data['language']);
		$this->assertNull($data['territory']);
		$this->assertNull($data['script']);
		$this->assertSame([], $data['options']);
	}

	/** @return array<string, array{0: mixed}> */
	public static function dataNotADeclaration(): array
	{
		return [
			'null, as an empty include would give' => [null],
			'a string' => ['$this->defaultDomain = "";'],
			'no translators key' => [['defaultDomain' => '', 'defaultLocale' => null, 'defaultTimeZone' => null, 'locales' => []]],
			'no locales key' => [['defaultDomain' => '', 'defaultLocale' => null, 'defaultTimeZone' => null, 'translators' => []]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('dataNotADeclaration')]
	public function testSomethingThatIsNotADeclarationIsRejectedWithAnUpgradeHint(mixed $compiled): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('clear the configuration cache');

		TranslationDefinitions::fromCompiled($compiled);
	}

	public function testAMalformedShapeIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('malformed');

		TranslationDefinitions::fromCompiled([
			'defaultDomain' => 'messages',
			'defaultLocale' => null,
			'defaultTimeZone' => null,
			'locales' => 'nope',
			'translators' => [],
		]);
	}

	public function testALocaleWithNoIdentifierIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('no identifier');

		TranslationDefinitions::fromCompiled([
			'defaultDomain' => 'messages',
			'defaultLocale' => 'en_GB',
			'defaultTimeZone' => null,
			'locales' => ['en_GB' => ['parameters' => []]],
			'translators' => [],
		]);
	}

	public function testATranslatorWithNoClassIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('no class');

		TranslationDefinitions::fromCompiled([
			'defaultDomain' => 'messages',
			'defaultLocale' => 'en_GB',
			'defaultTimeZone' => null,
			'locales' => [],
			'translators' => ['messages' => ['msg' => ['parameters' => []]]],
		]);
	}

	public function testATranslatorClassThatDoesNotExistIsRejectedByName(): void
	{
		$declaration = $this->declaration();
		$declaration['translators']['messages']['msg']['class'] = 'NoSuchTranslatorAnywhere';

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('NoSuchTranslatorAnywhere');

		TranslationDefinitions::fromCompiled($declaration);
	}

	public function testATranslatorClassThatIsNotATranslatorIsRejected(): void
	{
		$declaration = $this->declaration();
		$declaration['translators']['messages']['msg']['class'] = \stdClass::class;

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('is not a');

		TranslationDefinitions::fromCompiled($declaration);
	}

	/**
	 * Filters are applied with call_user_func(). One that is not callable would otherwise fail deep
	 * inside a translate() call, naming the filter machinery rather than the configuration entry
	 * that is actually wrong.
	 */
	public function testANonCallableFilterIsRejectedNamingTheDomainAndType(): void
	{
		$declaration = $this->declaration();
		$declaration['translators']['messages']['msg']['filters'] = ['not_a_real_function_anywhere'];

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('not callable');

		TranslationDefinitions::fromCompiled($declaration);
	}

	public function testACallableFilterIsAccepted(): void
	{
		$declaration = $this->declaration();
		$declaration['translators']['messages']['msg']['filters'] = ['strtoupper', 'trim'];

		$definitions = TranslationDefinitions::fromCompiled($declaration);

		$this->assertSame(
			['strtoupper', 'trim'],
			$definitions->translators['messages']['msg']['filters'],
		);
	}

	/**
	 * The property the redesign exists for: the compiled output cannot reach into whatever includes
	 * it, and in this handler's case it used to call a method on it too.
	 */
	public function testTheCompiledOutputNeverAssignsIntoOrCallsItsIncluder(): void
	{
		$handler = new TranslationConfigHandler();
		$handler->initialize(null, []);

		$code = $handler->executeArray([
			'default_domain' => 'messages',
			'default_locale' => 'en_GB',
			'locales' => ['en_GB' => ['name' => 'en_GB', 'params' => [], 'fallback' => null, 'ldml_file' => null]],
			'translators' => [
				'messages' => ['msg' => ['class' => SimpleTranslator::class, 'params' => [], 'filters' => []]],
			],
		], 'translation.xml');

		$this->assertStringNotContainsString('$this->', $code);
		$this->assertStringNotContainsString('getContext()', $code);
		$this->assertStringContainsString('return ', $code);
	}
}
