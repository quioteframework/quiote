<?php

use Quiote\Config\CompiledArtifact;
use Quiote\Config\Config;
use Quiote\Config\Factory\FactoryDefinitions;
use Quiote\Config\FactoryConfigHandler;
use Quiote\Exception\ConfigurationException;
use Quiote\User\ISecurityUser;
use Quiote\Context;
require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class FCHTestBase
{
	public mixed $context = null;
	/** @var array<string, mixed> */
	public array $params = [];
	public ?bool $suCalled = null;
	/**
	 * @param array<string, mixed> $params
	 */
	public function initialize(mixed $ctx, array $params = []): void
	{
		$this->context = $ctx;
		$this->params = $params;
	}
	public final function getContext(): mixed
	{
		return $this->context;
	}
	public function startup(): void
	{
		$this->suCalled = true;
	}
}

class FCHTestController         extends FCHTestBase {}
	

class FCHTestRequest            extends FCHTestBase {}
class FCHTestResponse           extends FCHTestBase {}
class FCHTestRouting            extends FCHTestBase {}
class FCHTestTranslationManager extends FCHTestBase {}
class FCHTestValidationManager  extends FCHTestBase {}
class FCHTestDBManager          extends FCHTestBase {}

// Legacy security filter removed
class FCHTestUser               extends FCHTestBase implements ISecurityUser
{
	public function addCredential($credential) {}
	public function clearCredentials() {}
	public function hasCredentials($credential): bool { return false; }
	public function isAuthenticated(): bool { return false; }
	public function removeCredential($credential) {}
	public function setAuthenticated($authenticated) {}
}

class FactoryConfigHandlerTest extends ConfigHandlerTestBase
{
	/** @var array<string|int, mixed> */
	protected array $conf = [];

	public function setUp(): void
	{
		parent::setUp();
		$this->conf = Config::toArray();
	}

	#[\Override]
    public function tearDown(): void
	{
		Config::clear();
		Config::fromArray($this->conf);
	}

	public function testFactoryConfigHandlerEmitsADeclarationNotStatements(): void
	{
		$FCH = new FactoryConfigHandler();

		$paramsExpected = ['p1' => 'v1', 'p2' => 'v2'];

		Config::set('core.use_database', true);
		Config::set('core.use_logging', true);
		Config::set('core.use_security', true);
		// factories.xsl gates the translation_manager block on core.use_translation;
		// set it explicitly so this test does not depend on the ambient value, which
		// other tests may have toggled off.
		Config::set('core.use_translation', true);
		$document = $this->parseConfiguration(
			Config::getString('core.config_dir') . '/tests/factories.xml',
			Config::getString('core.quiote_dir') . '/Config/xsl/factories.xsl'
		);

		// The compiled file returns its declaration. It no longer assigns into whatever included
		// it, which is why this reads the value instead of inspecting properties on $this.
		$definitions = FactoryDefinitions::fromCompiled(
			$FCH->execute($document),
			'the test fixture',
		);

		// On-demand slots: not built at boot, instantiated when asked for.
		$this->assertSame(
			[
				'validation_manager' => ['class' => 'FCHTestValidationManager', 'parameters' => $paramsExpected],
				'response' => ['class' => 'FCHTestResponse', 'parameters' => $paramsExpected],
			],
			$definitions->factories,
		);

		// Eagerly built roles, in construction order. The order is the contract: the database
		// manager exists before the user that may read through it.
		$this->assertSame(
			['database_manager', 'translation_manager', 'routing', 'request', 'controller', 'user'],
			$definitions->builtRoles(),
		);

		foreach ([
			'database_manager' => 'FCHTestDBManager',
			'translation_manager' => 'FCHTestTranslationManager',
			'routing' => 'FCHTestRouting',
			'request' => 'FCHTestRequest',
			'controller' => 'FCHTestController',
			'user' => 'FCHTestUser',
		] as $role => $class) {
			$this->assertSame(
				['class' => $class, 'parameters' => $paramsExpected],
				$definitions->buildInfo($role),
				"declaration for $role",
			);
		}

		// Shutdown is the reverse of startup order.
		$this->assertSame(
			['controller', 'routing', 'user', 'translation_manager', 'database_manager'],
			$definitions->shutdownOrder,
		);
	}

	/**
	 * The interleaving that a flat "build all, then start all" would lose: the database manager
	 * starts up before the components built after it, because they may read through it.
	 */
	public function testTheEmittedOperationsInterleaveBuildsAndStartups(): void
	{
		Config::set('core.use_database', true);
		Config::set('core.use_translation', true);
		$FCH = new FactoryConfigHandler();

		$definitions = FactoryDefinitions::fromCompiled(
			$FCH->executeArray($this->baseFactories(), 'tests/factories.xml'),
			'the test fixture',
		);

		$sequence = array_map(
			static fn(array $op): string => $op['op'] . ':' . $op['role'],
			$definitions->operations,
		);

		$dbStartup = array_search('startup:database_manager', $sequence, true);
		$userBuild = array_search('build:user', $sequence, true);
		$this->assertIsInt($dbStartup);
		$this->assertIsInt($userBuild);
		$this->assertLessThan(
			$userBuild,
			$dbStartup,
			'the database manager must be started up before the user is built',
		);
	}

	/**
	 * The compiled output must not be executable against its includer any more. This is the
	 * property the redesign exists for, so it is asserted rather than assumed.
	 */
	public function testTheCompiledOutputNeverAssignsIntoItsIncluder(): void
	{
		Config::set('core.use_translation', true);
		$FCH = new FactoryConfigHandler();

		$code = $FCH->executeArray($this->baseFactories(), 'tests/factories.xml');

		$source = CompiledArtifact::source($code, 'tests/factories.xml', $FCH::class);
		$this->assertStringNotContainsString('$this->', $source);
		$this->assertStringContainsString('return ', $source);
	}

	/**
	 * core.use_translation=true makes translation_manager conditionally
	 * required (see FactoryConfigHandler::getFactoryDefinitions()), but a
	 * freshly scaffolded app's factories file has no entry for it at all --
	 * the generic "missing or incomplete entry" message alone gives no hint
	 * that a new factory entry needs adding, or which class to point it at.
	 */
	public function testMissingTranslationManagerGivesActionableHint(): void
	{
		Config::set('core.use_translation', true);
		$FCH = new FactoryConfigHandler();

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('This entry becomes required once "core.use_translation" is enabled');

		$FCH->executeArray([
			'response' => ['class' => 'FCHTestResponse', 'params' => []],
			'validation_manager' => ['class' => 'FCHTestValidationManager', 'params' => []],
			'database_manager' => ['class' => 'FCHTestDBManager', 'params' => []],
			'routing' => ['class' => 'FCHTestRouting', 'params' => []],
			'request' => ['class' => 'FCHTestRequest', 'params' => []],
			'controller' => ['class' => 'FCHTestController', 'params' => []],
			'user' => ['class' => 'FCHTestUser', 'params' => []],
			// translation_manager deliberately omitted
		], 'tests/factories.xml');
	}

	/**
	 * @return array<string, array{class: string, params: array<mixed>}>
	 */
	private function baseFactories(): array
	{
		return [
			'response' => ['class' => 'FCHTestResponse', 'params' => []],
			'validation_manager' => ['class' => 'FCHTestValidationManager', 'params' => []],
			'database_manager' => ['class' => 'FCHTestDBManager', 'params' => []],
			'routing' => ['class' => 'FCHTestRouting', 'params' => []],
			'request' => ['class' => 'FCHTestRequest', 'params' => []],
			'controller' => ['class' => 'FCHTestController', 'params' => []],
			'user' => ['class' => 'FCHTestUser', 'params' => []],
			// Present regardless of core.use_translation, so these tests assert
			// on the session slot rather than on an unrelated global flag.
			'translation_manager' => ['class' => 'FCHTestTranslationManager', 'params' => []],
		];
	}

	public function testAnUnconfiguredSessionSlotEmitsNothing(): void
	{
		$FCH = new FactoryConfigHandler();

		$code = $FCH->executeArray($this->baseFactories(), 'tests/factories.xml');

		$this->assertStringNotContainsString("'session'", var_export($code, true), 'a context with no session configures nothing');
	}

	public function testSessionSlotEmitsFactoryInfoForTheConfiguredBackend(): void
	{
		$FCH = new FactoryConfigHandler();

		$definitions = FactoryDefinitions::fromCompiled($FCH->executeArray($this->baseFactories() + [
			'session' => ['class' => \Quiote\Session\FileSessionFactory::class, 'params' => ['dir' => '/tmp/quiote-sessions']],
		], 'tests/factories.xml'), 'the test fixture');

		// An on-demand slot, not an eagerly built component: no SessionPersistenceInterface has the
		// initialize($context, $params) shape the build operation calls.
		$this->assertSame(
			[
				'class' => \Quiote\Session\FileSessionFactory::class,
				'parameters' => ['dir' => '/tmp/quiote-sessions'],
			],
			$definitions->factories['session'] ?? null,
		);
		$this->assertNotContains('session', $definitions->builtRoles());
	}

	/**
	 * The session slot is the first to carry a must_implement constraint, so
	 * this is also the first exercise of that check against a real interface.
	 */
	public function testSessionSlotRejectsAClassThatIsNotASessionFactory(): void
	{
		$FCH = new FactoryConfigHandler();

		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('does not implement interface');

		$FCH->executeArray($this->baseFactories() + [
			'session' => ['class' => 'FCHTestValidationManager', 'params' => []],
		], 'tests/factories.xml');
	}

}
?>