<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Util\VirtualArrayPath;
use Quiote\Validator\DependencyManager;

class MyDependencyManager extends DependencyManager
{
	public function setDepData($data) { $this->depData = $data; }
}

class DependencyManagerTest extends UnitTestCase
{
	public function testclear()
	{
		$m = new MyDependencyManager;
		
		$m->setDepData([1]);
		$m->clear();
		$this->assertEquals($m->getDependTokens(), []);
	}
	
	public function testcheckDependencies()
	{
		$m = new MyDependencyManager;
		$m->setDepData(['foo' => true, 'bar' => true]);
		
		$this->assertEquals($m->checkDependencies(['foo', 'bar'], new VirtualArrayPath('')), true);
		$this->assertEquals($m->checkDependencies(['foo'], new VirtualArrayPath('')), true);
		$this->assertEquals($m->checkDependencies(['foo', 'bar', 'foobar'], new VirtualArrayPath('')), false);
		$this->assertEquals($m->checkDependencies(['foo'], new VirtualArrayPath('')), true);
		$this->assertEquals($m->checkDependencies(['%s[foo]'], new VirtualArrayPath('bar')), false);
		
		$m->setDepData(['foo' => ['bar' => true]]);
		$this->assertEquals($m->checkDependencies(['foo'], new VirtualArrayPath('')), true);
		$this->assertEquals($m->checkDependencies(['%s[bar]'], new VirtualArrayPath('foo')), true);
		$this->assertEquals($m->checkDependencies([], new VirtualArrayPath('')), true);
	}
	
	public function testaddDependTokens()
	{
		$m = new MyDependencyManager;
		
		$m->addDependTokens(['foo', 'bar'], new VirtualArrayPath(''));
		$this->assertEquals($m->getDependTokens(), ['foo' => true, 'bar' => true]);
		$m->addDependTokens(['%s[test]', '%s[test2]'], new VirtualArrayPath('foobar'));
		$this->assertEquals($m->getDependTokens(), ['foo' => true, 'bar' => true, 'foobar' => ['test' => true, 'test2' => true]]);
	}
}
?>
