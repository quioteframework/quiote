<?php

use Agavi\AgaviContext;
use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Config\AgaviConfig;
use Agavi\Exception\AgaviException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

require_once(__DIR__ . '/../../../lib/routing/AgaviTestingRouting.class.php');
require_once(__DIR__ . '/../../../lib/routing/TestTicket713RoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/TestTicket698RoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/TestTicket695RoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/Ticket1051RoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenSetExtraParamRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenSetExtraParamRoutingValueRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/MatchingRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/NonMatchingRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/OnNotMatchedRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenWithParamRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenWithUnescapedParamRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenUnsetRouteParamRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenUnsetExtraParamRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenNullifyRouteParamRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenNullifyExtraParamRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenSetPrefixAndPostfixRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenSetPrefixAndPostfixIntoRouteRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenChangeExtraParamRoutingValueRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenChangeExtraParamRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenDecodeParameterCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenObjectRoutingCallback.class.php');
require_once(__DIR__ . '/../../../lib/routing/GenChangeExtraParamRoutingCallback.class.php');
// Load specific callback that throws exception (overrides the one from AgaviTestingRoutingCallbacks.class.php)
require_once(__DIR__ . '/../../../lib/routing/OnNotMatchedRoutingCallback.class.php');

class AgaviRoutingTest extends AgaviUnitTestCase
{
	protected $routing;
	protected $parameters = array('enabled' => true);
	

	
	public function setUp(): void
	{
		parent::setUp();
		$this->markTestSkipped('Legacy container-based routing tests skipped during Symfony routing migration.');
		$this->routing = new AgaviTestingRouting();
		$this->routing->initialize($this->getContext(), $this->parameters);
		$this->routing->startup();
	}
	
	#[RunInSeparateProcess]
	public function testExecuteDisabled()
	{
		$this->routing->setParameter('enabled', false);
		$container = $this->routing->execute();
		$this->assertEquals(null, $container->getActionName());
		$this->assertEquals(null, $container->getModuleName());
	}
	
	#[RunInSeparateProcess]
	public function testExecuteEmptyInput()
	{
		$this->routing->forceInput('');
		$container = $this->routing->execute();
		$this->assertEquals(AgaviConfig::get('actions.error_404_action'), $container->getActionName());
		$this->assertEquals(AgaviConfig::get('actions.error_404_module'), $container->getModuleName());
		$this->assertEquals(array(), $this->getContext()->getRequest()->getAttribute('matched_routes', 'org.agavi.routing'));
	}
	
	#[RunInSeparateProcess]
	public function testExecuteSimpleInput()
	{
		$this->routing->forceInput('/');
		$container = $this->routing->execute();
		$this->assertEquals(AgaviConfig::get('actions.default_action'), $container->getActionName());
		$this->assertEquals(AgaviConfig::get('actions.default_module'), $container->getModuleName());
		$this->assertEquals(array('index'), $this->getContext()->getRequest()->getAttribute('matched_routes', 'org.agavi.routing'));
	}
	
	#[RunInSeparateProcess]
	public function testExecuteUserAuthenticated()
	{
		$ctx = $this->getContext();
		$ctx->getUser()->setAuthenticated(true);
		$this->routing->forceInput('/');
		$container = $this->routing->execute();
		$this->assertEquals('LoggedIn', $container->getActionName());
		$this->assertEquals('Auth', $container->getModuleName());
		$this->assertEquals(array('user_logged_in'), $ctx->getRequest()->getAttribute('matched_routes', 'org.agavi.routing'));
		$ctx->getUser()->setAuthenticated(false);
	}
	
	#[RunInSeparateProcess]
	public function testExecuteServer()
	{	
		$_SERVER['routing_test'] = 'foo';
		$ctx = $this->getContext();
		$this->routing->forceInput('/');
		$this->routing->setRoutingSource('_SERVER', $_SERVER);
		$container = $this->routing->execute();
		$this->assertEquals('Matched', $container->getActionName());
		$this->assertEquals('Server', $container->getModuleName());
		$this->assertEquals(array('server'), $ctx->getRequest()->getAttribute('matched_routes', 'org.agavi.routing'));
		// Clean up
		unset($_SERVER['routing_test']);
	}
		#[RunInSeparateProcess]
	public function testExecuteRandomSource()
	{
		$data = array();
		$data['bar'] = 'foo';
		$ctx = $this->getContext();
		$this->routing->forceInput('/');
		$this->routing->setRoutingSource('testingsource', $data);
		$container = $this->routing->execute();
		$this->assertEquals('Matched', $container->getActionName());
		$this->assertEquals('TestingSource', $container->getModuleName());
		$this->assertEquals(array('testingsource'), $ctx->getRequest()->getAttribute('matched_routes', 'org.agavi.routing'));
	}
	
	/*
	public function testExecuteNonexistantSource()
	{	
		$ctx = $this->getContext();
		$this->routing->forceInput('/');
		$container = $this->routing->execute();
		$this->assertEquals('Matched', $container->getActionName());
		$this->assertEquals('TestingSource', $container->getModuleName());
		$this->assertEquals(array('testingsource'), $ctx->getRequest()->getAttribute('matched_routes', 'org.agavi.routing'));
	}*/

	#[RunInSeparateProcess]
	public function testMatchWithParam()
	{
		$ctx = $this->getContext();
		$this->routing->forceInput('/withparam/5');
		$container = $this->routing->execute();
		$this->assertEquals(array('with_param'), $this->getContext()->getRequest()->getAttribute('matched_routes', 'org.agavi.routing'));
		$this->assertEquals(5, $ctx->getRequest()->getRequestData()->getParameter('number'));
		$this->assertEquals('MatchedParam', $container->getActionName());
		$this->assertEquals('TestWithParam', $container->getModuleName());
	}
	
	#[RunInSeparateProcess]
	public function testMatchWithMultipleParams()
	{
		$ctx = $this->getContext();
		$this->routing->forceInput('/withmultipleparams/5/foo');
		$container = $this->routing->execute();
		$this->assertEquals(array('with_two_params'), $this->getContext()->getRequest()->getAttribute('matched_routes', 'org.agavi.routing'));
		$this->assertEquals(5, $ctx->getRequest()->getRequestData()->getParameter('number'));
		$this->assertEquals('foo', $ctx->getRequest()->getRequestData()->getParameter('string'));
		$this->assertEquals('MatchedMultipleParams', $container->getActionName());
		$this->assertEquals('TestWithParam', $container->getModuleName());
	}
	
	#[RunInSeparateProcess]
	public function testOnNotMatched()
	{
		$this->routing->forceInput('/callbacks/on_not_matched/callback_stopper');
		$exceptionThrown = false;
		try {
			$container = $this->routing->execute();
		} catch (AgaviException $e) {
			$exceptionThrown = true;
			$this->assertEquals('Not Matched', $e->getMessage());
		}
		$this->assertTrue($exceptionThrown, 'Expected AgaviException with "Not Matched" message was not thrown');
	}
	
	#[RunInSeparateProcess]
	public function testNonMatchingCallback()
	{
		$this->routing->forceInput('/callbacks/nonmatching_callback');
		$container = $this->routing->execute();
		$this->assertEquals(array('callbacks'), $this->getContext()->getRequest()->getAttribute('matched_routes', 'org.agavi.routing'));
		$this->assertEquals(AgaviConfig::get('actions.error_404_module'), $container->getModuleName());
		$this->assertEquals(AgaviConfig::get('actions.error_404_action'), $container->getActionName());
	}
	
	#[RunInSeparateProcess]
	public function testMatchingCallback()
	{
		$ctx = $this->getContext();
		$this->routing->forceInput('/callbacks/matching_callback');
		$container = $this->routing->execute();
		$this->assertEquals(array('callbacks', 'callbacks.matching_callback'), $ctx->getRequest()->getAttribute('matched_routes', 'org.agavi.routing'));
		$this->assertEquals('Callback', $container->getModuleName());
		$this->assertEquals('Matching', $container->getActionName());
		$this->assertEquals('set', $ctx->getRequest()->getRequestData()->getParameter('callback'));
	}
	
	#[RunInSeparateProcess]
	public function testOnNotMatchedStopper()
	{
		$this->routing->forceInput('/callbacks/stopper');
		$exceptionThrown = false;
		try {
			$container = $this->routing->execute();
		} catch (AgaviException $e) {
			$exceptionThrown = true;
			$this->fail('The onNotMatched callback of the childroute should not get called');
		}
		$this->assertFalse($exceptionThrown, 'No exception should have been thrown');
	}
	
	/**
	 * 
	 */
	#[RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\DataProvider('dataParseRouteString')]
	public function testParseRouteString($routeString, $expected)
	{
		$parsed = $this->routing->parseRouteString($routeString);
		$this->assertEquals($expected, $parsed);
	}
	
	public static function dataParseRouteString()
	{
		return array(
			'escaped_balanced' => array(
				'static\(text(prefix{foo:1\(2\{3\}4\)5}postfix)',
				array(
					'#static\(text(prefix(?P<foo>1(2{3}4)5)postfix)#',
					'static(text(:foo:)',
					array('foo' => array(
						'pre'  => 'prefix',
						'val'  => '',
						'post' => 'postfix',
						'is_optional' => false,
					)),
					0,
				)
			),
			'#789' => array(
				'#static#with#quote',
				array(
					'#\#static\#with\#quote#',
					'#static#with#quote',
					array(),
					0,
				)
			),
		);
	}
	
	#[RunInSeparateProcess]
	public function testTicket263()
	{
		try {
			$this->routing->addRoute('rxp', array('name' => 'foo'));
			$this->routing->addRoute('rxp', array('name' => 'foo'), 'foo');
			$this->fail('succeeded in adding a route with the same name as a child');
		} catch (AgaviException $e) {
			$this->assertEquals('You are trying to overwrite a route but are not staying in the same hierarchy', $e->getMessage());
		}
		
	}
	
	#[RunInSeparateProcess]
	public function testTicket764()
	{
		$this->routing->forceInput('/test_ticket_764/dummy/child');
		$container = $this->routing->execute();
		$this->assertEquals('Default', $container->getModuleName());
		$this->assertEquals('Foo/Bar', $container->getActionName());
	}
	
	#[RunInSeparateProcess]
	public function testEmptyDefaultValue() {
		$this->routing->forceInput('/empty_default_value');
		$container = $this->routing->execute();
		$rd = $this->getContext()->getRequest()->getRequestData();
		$this->assertSame('0', $rd->getParameter('value'));
	}
}


?>