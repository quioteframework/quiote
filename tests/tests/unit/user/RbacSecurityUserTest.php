<?php

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Quiote\Context;
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

	#[RunInSeparateProcess]
	public function testTokenDerivedRolesAreNotRehydratedFromStaleSession(): void
	{
		// NullStorage (the default test storage) discards everything, so
		// persistence across separate User instances needs a real,
		// dedicated (SessionStorage-backed) context -- see factories.xml.
		$context = Context::getInstance('rbac-security-user-test::tests-token-derived-persistence');

		// A session that actually retains data: no middleware runs in a unit
		// test, so the context would otherwise answer a NullSessionBag.
		$context->setSessionBag(new InMemorySessionBag());

		$u = new SimpleRbacSecurityUser();
		$u->initialize($context);
		$u->setAuthenticated(true);
		$u->grantRole('admin');
		$u->markTokenDerived();
		$u->shutdown();

		$fresh = new SimpleRbacSecurityUser();
		$fresh->initialize($context);

		$this->assertTrue($fresh->isTokenDerived());
		$this->assertSame([], $fresh->getRoles());
		$this->assertSame([], $fresh->getCredentials());
	}

	#[RunInSeparateProcess]
	public function testLoadDefinitionsReadsFromDiskOnFirstCallAndCachesPerWorker(): void
	{
		// Must match core.config_dir + '/tests/rbac_definitions.xml' exactly
		// (including the config system's own path concatenation quirks) since
		// ConfigCache resolves handlers by exact-string key against the
		// compiled config_handlers.xml, not a normalized/realpath()'d path.
		$path = \Quiote\Config\Config::getString('core.config_dir') . '/tests/rbac_definitions.xml';
		$u = new RbacSecurityUser();
		$u->initialize($this->getContext());
		$u->setParameter('definitions_file', $path);

		$method = new ReflectionMethod(RbacSecurityUser::class, 'loadDefinitions');
		$method->invoke($u);

		$definitionsProp = new ReflectionProperty(RbacSecurityUser::class, 'definitions');
		$definitions = $definitionsProp->getValue($u);
		$this->assertIsArray($definitions);
		$this->assertArrayHasKey('guest', $definitions);

		$cacheProp = new ReflectionProperty(RbacSecurityUser::class, 'definitionsCache');
		$cacheKey = $path . '|' . $this->getContext()->getName();
		/** @var array<string, mixed> $cache */
		$cache = $cacheProp->getValue();
		$this->assertArrayHasKey($cacheKey, $cache);
	}

	#[RunInSeparateProcess]
	public function testLoadDefinitionsReusesPerWorkerCacheInsteadOfRereadingDisk(): void
	{
		// Must match core.config_dir + '/tests/rbac_definitions.xml' exactly
		// (including the config system's own path concatenation quirks) since
		// ConfigCache resolves handlers by exact-string key against the
		// compiled config_handlers.xml, not a normalized/realpath()'d path.
		$path = \Quiote\Config\Config::getString('core.config_dir') . '/tests/rbac_definitions.xml';
		$cacheKey = $path . '|' . $this->getContext()->getName();

		// Seed the static cache directly with a sentinel value distinct from
		// what the real XML file would parse to; a fresh instance sharing the
		// same (path, context) key must come back with this sentinel rather
		// than reparsing the file, proving the cache path was actually taken.
		$sentinel = ['sentinel-role' => ['permissions' => ['sentinel.permission']]];
		$cacheProp = new ReflectionProperty(RbacSecurityUser::class, 'definitionsCache');
		$cacheProp->setValue(null, [$cacheKey => $sentinel]);

		$u = new RbacSecurityUser();
		$u->initialize($this->getContext());
		$u->setParameter('definitions_file', $path);

		$method = new ReflectionMethod(RbacSecurityUser::class, 'loadDefinitions');
		$method->invoke($u);

		$definitionsProp = new ReflectionProperty(RbacSecurityUser::class, 'definitions');
		$this->assertSame($sentinel, $definitionsProp->getValue($u));
	}
}
?>