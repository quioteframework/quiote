<?php

use Quiote\Testing\UnitTestCase;
use Quiote\User\SecurityUser;
use Quiote\Context;

class SampleSecurityUser extends SecurityUser
{
	#[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		parent::initialize($context, $parameters);
		$this->context = $context;
		
		if(count($parameters)) {
			$this->attributes = $parameters;
		}
		$this->attributes = [];
	}
}


class SecurityUserTest extends UnitTestCase
{

	protected $context;
	
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;
		
		if(count($parameters)) {
			$this->setParameters($parameters);
		}
		$this->attributes = [];
	}
	
	private $_u = null;

	#[\Override]
    public function setUp(): void
	{
		$this->_u = new SampleSecurityUser();
		$this->_u->initialize($this->getContext());
		// The authenticated flag and credentials live in the shared storage/session,
		// so a prior test that authenticated a user (e.g. setAuthenticated(true) in
		// the dispatch/slot middleware tests) would otherwise leak in and make this
		// fresh user report isAuthenticated()===true. Establish a clean baseline.
		$this->_u->setAuthenticated(false);
		$this->_u->clearCredentials();
	}

	public function testaddCredential()
	{
		$this->_u->clearCredentials();
		$this->_u->addCredential('test1');
		$this->assertTrue($this->_u->hasCredentials('test1'));
		$this->_u->addCredential('test2');
		$this->assertTrue($this->_u->hasCredentials('test2'));
	}
	
	public function testhasCredentials()
	{
		$this->_u->clearCredentials();
		$this->_u->addCredential('test1');
		$this->_u->addCredential('test2');
		$this->_u->addCredential('test3');
		$this->_u->addCredential('test4');
		$this->assertTrue($this->_u->hasCredentials('test1'));
		$this->assertTrue($this->_u->hasCredentials(['test2', 'test3']));
		$this->assertTrue($this->_u->hasCredentials(['test1', ['test2', 'test3']]));
		$this->assertTrue($this->_u->hasCredentials(['test1', ['test2', 'test5']]));
		$this->assertFalse($this->_u->hasCredentials('test5'));
		$this->assertFalse($this->_u->hasCredentials(['test2', 'test5']));
		$this->assertFalse($this->_u->hasCredentials(['test5', ['test2', 'test3']]));
		$this->assertFalse($this->_u->hasCredentials(['test1', ['test5', 'test6']]));
	}
	
	public function teststrictCredentialComparison()
	{
		$this->_u->clearCredentials();
		$this->_u->addCredential('0');
		$this->assertTrue($this->_u->hasCredentials('0'));
		$this->assertFalse($this->_u->hasCredentials(0));
		$this->assertFalse($this->_u->hasCredentials(false));
	}

	public function testRemoveCredential()
	{
		$this->_u->clearCredentials();
		$this->_u->addCredential('test1');
		$this->_u->addCredential('test2');
		$this->_u->addCredential('test3');
		$this->assertTrue($this->_u->hasCredentials(['test1', 'test2', 'test3']));
		$this->_u->removeCredential('test2');
		$this->assertTrue($this->_u->hasCredentials(['test3', 'test1']));
		$this->assertFalse($this->_u->hasCredentials(['test1', 'test2', 'test3']));
		$this->assertFalse($this->_u->hasCredentials(['test2']));
		$this->_u->removeCredential('test1');
		$this->assertTrue($this->_u->hasCredentials(['test3']));
		$this->assertFalse($this->_u->hasCredentials(['test1']));
		$this->_u->removeCredential('test3');
		$this->assertFalse($this->_u->hasCredentials(['test3']));
	}

	public function testSetIsAuthenticated()
	{
		$u = $this->_u;
		$this->assertFalse($u->isAuthenticated());
		$u->setAuthenticated(1);
		$this->assertFalse($u->isAuthenticated());
		$u->setAuthenticated(true);
		$this->assertTrue($u->isAuthenticated());
		$u->setAuthenticated(1);
		$this->assertFalse($u->isAuthenticated());
		$u->setAuthenticated(true);
		$this->assertTrue($u->isAuthenticated());
		$u->setAuthenticated(false);
		$this->assertFalse($u->isAuthenticated());
	}

}
?>