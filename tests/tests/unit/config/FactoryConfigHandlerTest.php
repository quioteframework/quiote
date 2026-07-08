<?php

use Quiote\Config\Config;
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
class FCHTestStorage            extends FCHTestBase {}
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
	// Prevent dynamic property deprecation when generated factory code assigns $this->shutdownSequence
	/** @var array<int, mixed> */
	public array $shutdownSequence = [];
	// Added to silence dynamic property creation deprecations from generated factories code
	/** @var array<string, mixed>|null */
	public ?array $databaseManagerFactoryInfo = null;
	/** @var array<string, mixed>|null */
	public ?array $translationManagerFactoryInfo = null;
	/** @var array<string, mixed>|null */
	public ?array $requestFactoryInfo = null;
	/** @var array<string, mixed>|null */
	public ?array $routingFactoryInfo = null;
	/** @var array<string, mixed>|null */
	public ?array $controllerFactoryInfo = null;
	/** @var array<string, mixed>|null */
	public ?array $storageFactoryInfo = null;
	/** @var array<string, mixed>|null */
	public ?array $userFactoryInfo = null;
	/** @var array<string|int, mixed> */
	protected array $conf = [];

	/** @var array<string, mixed> */
	protected array $factories = [];

	protected ?FCHTestDBManager $databaseManager = null;
	protected ?FCHTestRequest $request = null;
	protected ?FCHTestStorage $storage = null;
	protected ?FCHTestTranslationManager $translationManager = null;
	protected ?FCHTestUser $user = null;
	protected ?FCHTestController $controller = null;
	protected ?FCHTestRouting $routing = null;
	protected ?FCHTestResponse $response = null;

	public function setUp(): void
	{
		parent::setUp();
		$this->conf = Config::toArray();
		$this->factories = [];
	}

	#[\Override]
    public function tearDown(): void
	{
		Config::clear();
		Config::fromArray($this->conf);
	}

	/**
	 * Asserts that the given value is an instance of $class and returns it as
	 * such, narrowing the type for static analysis without relying on the
	 * (not installed) phpstan-phpunit extension to understand assertInstanceOf().
	 * @template T of FCHTestBase
	 * @param class-string<T> $class
	 * @return T
	 */
	private function assertInstanceOfAndNarrow(string $class, mixed $actual): FCHTestBase
	{
		$this->assertInstanceOf($class, $actual);
		if (!$actual instanceof $class) {
			$this->fail(sprintf('Expected an instance of %s.', $class));
		}
		return $actual;
	}

	public function testFactoryConfigHandler(): void
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
		$this->includeCode($FCH->execute($document));



	// Legacy filters removed – no assertions

		// Response (now includes factory_info metadata)
		$this->assertSame(
			[
				'class' => 'FCHTestResponse',
				'parameters' => $paramsExpected,
				'factory_info' => [
					'class' => 'FCHTestResponse',
					'parameters' => $paramsExpected,
				],
			],
			$this->factories['response']
		);
		

		// Validation Manager (includes factory_info)
		$this->assertSame(
			[
				'class' => 'FCHTestValidationManager',
				'parameters' => $paramsExpected,
				'factory_info' => [
					'class' => 'FCHTestValidationManager',
					'parameters' => $paramsExpected,
				],
			],
			$this->factories['validation_manager']
		);

		$databaseManager = $this->assertInstanceOfAndNarrow(FCHTestDBManager::class, $this->databaseManager);
		$this->assertSame($this, $databaseManager->context);
		$this->assertSame($paramsExpected, $databaseManager->params);
		$this->assertTrue($databaseManager->suCalled);

		$request = $this->assertInstanceOfAndNarrow(FCHTestRequest::class, $this->request);
		$this->assertSame($this, $request->context);
		$this->assertSame($paramsExpected, $request->params);
		// Request startup is no longer executed automatically; PSR-7 bootstrap handles initialization lazily.
		$this->assertNull($request->suCalled);

		$storage = $this->assertInstanceOfAndNarrow(FCHTestStorage::class, $this->storage);
		$this->assertSame($this, $storage->context);
		$this->assertSame($paramsExpected, $storage->params);
		$this->assertTrue($storage->suCalled);

		$translationManager = $this->assertInstanceOfAndNarrow(FCHTestTranslationManager::class, $this->translationManager);
		$this->assertSame($this, $translationManager->context);
		$this->assertSame($paramsExpected, $translationManager->params);
		$this->assertTrue($translationManager->suCalled);

		$user = $this->assertInstanceOfAndNarrow(FCHTestUser::class, $this->user);
		$this->assertSame($this, $user->context);
		$this->assertSame($paramsExpected, $user->params);
		$this->assertTrue($user->suCalled);

		$controller = $this->assertInstanceOfAndNarrow(FCHTestController::class, $this->controller);
		$this->assertSame($this, $controller->context);
		$this->assertSame($paramsExpected, $controller->params);

		$routing = $this->assertInstanceOfAndNarrow(FCHTestRouting::class, $this->routing);
		$this->assertSame($this, $routing->context);
		$this->assertSame($paramsExpected, $routing->params);
		$this->assertTrue($routing->suCalled);
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
			'storage' => ['class' => 'FCHTestStorage', 'params' => []],
			'user' => ['class' => 'FCHTestUser', 'params' => []],
			// translation_manager deliberately omitted
		], 'tests/factories.xml');
	}

}
?>