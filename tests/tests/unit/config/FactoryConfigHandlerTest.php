<?php

use Quiote\Config\Config;
use Quiote\Config\FactoryConfigHandler;
use Quiote\Exception\ConfigurationException;
use Quiote\User\ISecurityUser;
use Quiote\Context;
require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class FCHTestBase
{
	public $context,
	       $params,
	       $suCalled;
	public function initialize($ctx, array $params = [])
	{
		$this->context = $ctx;
		$this->params = $params;
	}
	public final function getContext()
	{
		return $this->context;
	}
	public function startup()
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
	public function hasCredentials($credential) {}
	public function isAuthenticated() {}
	public function removeCredential($credential) {}
	public function setAuthenticated($authenticated) {}
}

class FactoryConfigHandlerTest extends ConfigHandlerTestBase
{
	// Prevent dynamic property deprecation when generated factory code assigns $this->shutdownSequence
	public array $shutdownSequence = [];
	// Added to silence dynamic property creation deprecations from generated factories code
	public ?array $databaseManagerFactoryInfo = null;
	public ?array $translationManagerFactoryInfo = null;
	public ?array $requestFactoryInfo = null;
	public ?array $routingFactoryInfo = null;
	public ?array $controllerFactoryInfo = null;
	public ?array $storageFactoryInfo = null;
	public ?array $userFactoryInfo = null;
	protected		$conf;

	protected		$factories;

	protected		$databaseManager,
							$request,
							$storage,
							$translationManager,
							$user,
							$controller,
							$routing,
							$response;

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

	public function testFactoryConfigHandler()
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

		$this->assertInstanceOf('FCHTestDBManager', $this->databaseManager);
		$this->assertSame($this, $this->databaseManager->context);
		$this->assertSame($paramsExpected, $this->databaseManager->params);
		$this->assertTrue($this->databaseManager->suCalled);

		$this->assertInstanceOf('FCHTestRequest', $this->request);
		$this->assertSame($this, $this->request->context);
		$this->assertSame($paramsExpected, $this->request->params);
		// Request startup is no longer executed automatically; PSR-7 bootstrap handles initialization lazily.
		$this->assertNull($this->request->suCalled);

		$this->assertInstanceOf('FCHTestStorage', $this->storage);
		$this->assertSame($this, $this->storage->context);
		$this->assertSame($paramsExpected, $this->storage->params);
		$this->assertTrue($this->storage->suCalled);

		$this->assertInstanceOf('FCHTestTranslationManager', $this->translationManager);
		$this->assertSame($this, $this->translationManager->context);
		$this->assertSame($paramsExpected, $this->translationManager->params);
		$this->assertTrue($this->translationManager->suCalled);

		$this->assertInstanceOf('FCHTestUser', $this->user);
		$this->assertSame($this, $this->user->context);
		$this->assertSame($paramsExpected, $this->user->params);
		$this->assertTrue($this->user->suCalled);

		$this->assertInstanceOf('FCHTestController', $this->controller);
		$this->assertSame($this, $this->controller->context);
		$this->assertSame($paramsExpected, $this->controller->params);

		$this->assertInstanceOf('FCHTestRouting', $this->routing);
		$this->assertSame($this, $this->routing->context);
		$this->assertSame($paramsExpected, $this->routing->params);
		$this->assertTrue($this->routing->suCalled);
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