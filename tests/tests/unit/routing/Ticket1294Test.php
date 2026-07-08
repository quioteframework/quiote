<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Context;

class Ticket1294Test extends PhpUnitTestCase
{
	protected \TestingWebRouting $routing;

	/** @var array<string, mixed> */
	protected array $parameters = ['enabled' => true];

	#[\Override]
    public function setUp(): void
	{
		// otherwise, the full URI wouldn't work
		$_SERVER['REQUEST_URI'] = '/index.php';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		
		$this->routing = new TestingWebRouting();
		$this->routing->initialize(Context::getInstance(null), $this->parameters);
		$this->routing->startup();
	}
	
	public function testQueryStringParametersCanBeUnsetUsingNull(): void
	{
		$this->routing->setInput('/ticket_1294');
		$this->routing->setInputParameters(['foo' => 'bar']);
		$url = $this->routing->gen(null, ['foo' => null]);
		$this->assertEquals('/index.php/ticket_1294', $url);
	}

	public function testRoutingValueSleepDoesNotFatalWhenNeverInitialized(): void
	{
		// initialize() (which assigns the Context) is only called by Routing internals; a
		// RoutingValue that is serialized before that happens must not fatally error trying
		// to call getName() on a null context.
		$value = new \Quiote\Routing\RoutingValue('some-value');
		$serialized = serialize($value);
		/** @var \Quiote\Routing\RoutingValue $restored */
		$restored = unserialize($serialized);
		$this->assertSame('some-value', $restored->getValue());
	}
}


?>