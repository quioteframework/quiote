<?php

use Quiote\Testing\UnitTestCase;
use Quiote\User\RbacSecurityUser;

class SimpleRbacSecurityUser extends RbacSecurityUser
{
	#[\Override]
    protected function loadDefinitions()
	{
		$this->definitions = [
			'guest' => [
				'permissions' => [
					'products.list',
					'products.view'
				]
			],
			'member' => [
				'parent' => 'guest',
				'permissions' => [
					'products.rate',
					'products.comment'
				]
			],
			'admin' => [
				'parent' => 'member',
				'permissions' => [
					'products.add',
					'products.edit',
					'products.remove'
				]
			]
		];
	}
	
	#[\Override]
    public function getCredentials()
	{
		return $this->credentials;
	}
}

class RbacSecurityUserTest extends UnitTestCase
{
	private SimpleRbacSecurityUser $_u;

	#[\Override]
    public function setUp(): void
	{
		$this->_u = new SimpleRbacSecurityUser();
		$this->_u->initialize($this->getContext());
	}

	public function testRoles(): void
	{
		$this->assertEquals($this->_u->getRoles(), []);
		
		$this->_u->grantRole('admin');
		$this->assertEquals($this->_u->getRoles(), ['admin']);
		$this->assertTrue($this->_u->hasCredentials(['products.add', 'products.rate', 'products.view']));
		
		$this->_u->revokeRole('admin');
		$this->assertEquals($this->_u->getRoles(), []);
		
		$this->_u->grantRole('member');
		$this->assertEquals($this->_u->getRoles(), [1 => 'member']);
		
		$this->assertTrue($this->_u->hasCredentials(['products.rate', 'products.view']));
		$this->assertFalse($this->_u->hasCredentials('products.edit'));
		
		$this->_u->grantRole('guest');
		$this->assertEquals($this->_u->getRoles(), [1 => 'member', 'guest']);
		$this->assertTrue($this->_u->hasCredentials('products.list'));
		$this->assertFalse($this->_u->hasCredentials('products.add'));

		$this->_u->revokeRole('member');
		$this->assertEquals($this->_u->getRoles(), [2 => 'guest']);
		$this->assertFalse($this->_u->hasCredentials('products.rate'));
		
		$this->_u->revokeAllRoles();
		$this->assertEquals($this->_u->getRoles(), []);
		$this->assertEquals($this->_u->getCredentials(), []);
	}
}
?>