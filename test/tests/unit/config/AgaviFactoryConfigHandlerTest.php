<?php

use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviFactoryConfigHandler;
use Agavi\User\AgaviISecurityUser;
use Agavi\AgaviContext;
require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class FCHTestBase
{
	public $context,
	       $params,
	       $suCalled;
	public function initialize($ctx, array $params = array())
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
	

class FCHTestLoggerManager      extends FCHTestBase {}
class FCHTestRequest            extends FCHTestBase {}
class FCHTestResponse           extends FCHTestBase {}
class FCHTestRouting            extends FCHTestBase {}
class FCHTestStorage            extends FCHTestBase {}
class FCHTestTranslationManager extends FCHTestBase {}
class FCHTestValidationManager  extends FCHTestBase {}
class FCHTestDBManager          extends FCHTestBase {}

// Legacy security filter removed
class FCHTestUser               extends FCHTestBase implements AgaviISecurityUser
{
	public function addCredential($credential) {}
	public function clearCredentials() {}
	public function hasCredentials($credential) {}
	public function isAuthenticated() {}
	public function removeCredential($credential) {}
	public function setAuthenticated($authenticated) {}
}

class AgaviFactoryConfigHandlerTest extends ConfigHandlerTestBase
{
	// Prevent dynamic property deprecation when generated factory code assigns $this->shutdownSequence
	public array $shutdownSequence = [];
	// Added to silence dynamic property creation deprecations from generated factories code
	public ?array $databaseManagerFactoryInfo = null;
	public ?array $loggerManagerFactoryInfo = null;
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
							$loggerManager,
							$controller,
							$routing,
							$response;

	public function setUp(): void
	{
		parent::setUp();
		$this->conf = AgaviConfig::toArray();
		$this->factories = array();
	}

	public function tearDown(): void
	{
		AgaviConfig::clear();
		AgaviConfig::fromArray($this->conf);
	}

	public function testFactoryConfigHandler()
	{
		$FCH = new AgaviFactoryConfigHandler();

		$paramsExpected = array('p1' => 'v1', 'p2' => 'v2');

		AgaviConfig::set('core.use_database', true);
		AgaviConfig::set('core.use_logging', true);
		AgaviConfig::set('core.use_security', true);
		// factories.xsl gates the translation_manager block on core.use_translation;
		// set it explicitly so this test does not depend on the ambient value, which
		// other tests may have toggled off.
		AgaviConfig::set('core.use_translation', true);
		$document = $this->parseConfiguration(
			AgaviConfig::get('core.config_dir') . '/tests/factories.xml',
			AgaviConfig::get('core.agavi_dir') . '/Config/xsl/factories.xsl'
		);
		$this->includeCode($FCH->execute($document));



	// Legacy filters removed – no assertions

		// Response (now includes factory_info metadata)
		$this->assertSame(
			array(
				'class' => 'FCHTestResponse',
				'parameters' => $paramsExpected,
				'factory_info' => array(
					'class' => 'FCHTestResponse',
					'parameters' => $paramsExpected,
				),
			),
			$this->factories['response']
		);
		

		// Validation Manager (includes factory_info)
		$this->assertSame(
			array(
				'class' => 'FCHTestValidationManager',
				'parameters' => $paramsExpected,
				'factory_info' => array(
					'class' => 'FCHTestValidationManager',
					'parameters' => $paramsExpected,
				),
			),
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

		$this->assertInstanceOf('FCHTestLoggerManager', $this->loggerManager);
		$this->assertSame($this, $this->loggerManager->context);
		$this->assertSame($paramsExpected, $this->loggerManager->params);
		$this->assertTrue($this->loggerManager->suCalled);

		$this->assertInstanceOf('FCHTestController', $this->controller);
		$this->assertSame($this, $this->controller->context);
		$this->assertSame($paramsExpected, $this->controller->params);

		$this->assertInstanceOf('FCHTestRouting', $this->routing);
		$this->assertSame($this, $this->routing->context);
		$this->assertSame($paramsExpected, $this->routing->params);
		$this->assertTrue($this->routing->suCalled);
	}

}
?>