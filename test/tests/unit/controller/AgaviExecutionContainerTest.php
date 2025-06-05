<?php
use Agavi\Testing\AgaviUnitTestCase;

class AgaviExecutionContainerTest extends AgaviUnitTestCase
{
	
	public function testSimpleActionWithoutArguments()
	{
		$container = $this->getContext()->getController()->createExecutionContainer('ControllerTests', 'SimpleAction');
		$response = $container->execute();
		
		// Verify the container was created and executed successfully
		$this->assertNotNull($container);
		$this->assertNotNull($response);
	}
}