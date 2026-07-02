<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\DirectiveExpander;

class DirectiveExpanderTest extends PhpUnitTestCase
{
	protected function tearDown(): void
	{
		Config::remove('test.directive_expander_check');
		Config::remove('test.directive_expander_name');
		parent::tearDown();
	}

	public function testExpandsDirectivesInStringLeaves()
	{
		// Use test-namespaced directive keys rather than real core.* ones,
		// so this never collides with (or has to restore) framework config
		// other tests depend on.
		Config::set('test.directive_expander_check', '/opt/quiote', true);
		$expander = new DirectiveExpander();

		$result = $expander->expand(['path' => '%test.directive_expander_check%/Config']);

		$this->assertSame(['path' => '/opt/quiote/Config'], $result);
	}

	public function testCoercesBooleanLikeStringsToRealBooleans()
	{
		$expander = new DirectiveExpander();
		$result = $expander->expand(['debug' => 'true', 'strict' => 'false', 'name' => 'yes']);

		$this->assertTrue($result['debug']);
		$this->assertFalse($result['strict']);
		$this->assertTrue($result['name']);
	}

	public function testRecursesIntoNestedArrays()
	{
		Config::set('test.directive_expander_name', 'MyApp', true);
		$expander = new DirectiveExpander();

		$result = $expander->expand(['db' => ['name' => '%test.directive_expander_name%_db']]);

		$this->assertSame(['db' => ['name' => 'MyApp_db']], $result);
	}

	public function testNonStringLeavesPassThroughUnchanged()
	{
		$expander = new DirectiveExpander();
		$result = $expander->expand(['count' => 5, 'ratio' => 1.5, 'enabled' => true, 'nothing' => null]);

		$this->assertSame(['count' => 5, 'ratio' => 1.5, 'enabled' => true, 'nothing' => null], $result);
	}

	public function testDoesNotMutateInputArray()
	{
		$input = ['path' => 'plain-string'];
		(new DirectiveExpander())->expand($input);
		$this->assertSame(['path' => 'plain-string'], $input);
	}
}
?>
