<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Util\AgaviVirtualArrayPath;
use Agavi\Validator\AgaviDependencyManager;

class MyDependencyManager extends AgaviDependencyManager
{
	public function setDepData($data) { $this->depData = $data; }
}

class AgaviDependencyManagerTest extends AgaviUnitTestCase
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
		
		$this->assertEquals($m->checkDependencies(['foo', 'bar'], new AgaviVirtualArrayPath('')), true);
		$this->assertEquals($m->checkDependencies(['foo'], new AgaviVirtualArrayPath('')), true);
		$this->assertEquals($m->checkDependencies(['foo', 'bar', 'foobar'], new AgaviVirtualArrayPath('')), false);
		$this->assertEquals($m->checkDependencies(['foo'], new AgaviVirtualArrayPath('')), true);
		$this->assertEquals($m->checkDependencies(['%s[foo]'], new AgaviVirtualArrayPath('bar')), false);
		
		$m->setDepData(['foo' => ['bar' => true]]);
		$this->assertEquals($m->checkDependencies(['foo'], new AgaviVirtualArrayPath('')), true);
		$this->assertEquals($m->checkDependencies(['%s[bar]'], new AgaviVirtualArrayPath('foo')), true);
		$this->assertEquals($m->checkDependencies([], new AgaviVirtualArrayPath('')), true);
	}
	
	public function testaddDependTokens()
	{
		$m = new MyDependencyManager;
		
		$m->addDependTokens(['foo', 'bar'], new AgaviVirtualArrayPath(''));
		$this->assertEquals($m->getDependTokens(), ['foo' => true, 'bar' => true]);
		$m->addDependTokens(['%s[test]', '%s[test2]'], new AgaviVirtualArrayPath('foobar'));
		$this->assertEquals($m->getDependTokens(), ['foo' => true, 'bar' => true, 'foobar' => ['test' => true, 'test2' => true]]);
	}
}
?>
