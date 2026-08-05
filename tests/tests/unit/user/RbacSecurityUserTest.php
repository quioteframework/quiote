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

		// The role list stays a gap-free list across revocations. It used to be
		// asserted here as [1 => 'member'], [1 => 'member', 'guest'] and
		// [2 => 'guest'] -- index gaps left behind by revokeRole()'s unset(), which
		// leaked an internal artifact into the public getRoles() contract (declared
		// array<int, string>). revokeRole() now re-derives the surviving set, so the
		// keys are sequential.
		$this->_u->grantRole('member');
		$this->assertEquals($this->_u->getRoles(), ['member']);

		$this->assertTrue($this->_u->hasCredentials(['products.rate', 'products.view']));
		$this->assertFalse($this->_u->hasCredentials('products.edit'));

		$this->_u->grantRole('guest');
		$this->assertEquals($this->_u->getRoles(), ['member', 'guest']);
		$this->assertTrue($this->_u->hasCredentials('products.list'));
		$this->assertFalse($this->_u->hasCredentials('products.add'));

		$this->_u->revokeRole('member');
		$this->assertEquals($this->_u->getRoles(), ['guest']);
		// 'guest' is a parent of 'member', so revoking 'member' must keep guest's
		// own permissions while dropping the ones only 'member' granted.
		$this->assertFalse($this->_u->hasCredentials('products.rate'));
		$this->assertTrue($this->_u->hasCredentials(['products.list', 'products.view']));
		
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
		$context->getContainer()->set(\Quiote\Session\SessionBagInterface::class, new InMemorySessionBag(), \Quiote\DI\Container::SCOPE_REQUEST);

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

	/**
	 * The definitions arrive from a config cache entry, so their shape is checked at the boundary: a
	 * malformed one names the file it came from instead of surfacing as a credential of the wrong type
	 * or an endless parent walk.
	 * @return array<string, array{mixed, string}>
	 */
	public static function malformedDefinitionsProvider(): array
	{
		return [
			'not a map' => ['guest', 'must be a map of role name => definition'],
			'role is not an array' => [['guest' => 'products.list'], 'Role "guest" .* must be an array'],
			'permissions are not a list' => [['guest' => ['permissions' => 'products.list']], 'permissions of role "guest" .* must be a list'],
			'permission is not a string' => [['guest' => ['permissions' => [['products.list']]]], 'permission of role "guest" .* must be a string'],
			'parent is not a role name' => [['member' => ['permissions' => [], 'parent' => 42]], 'parent of role "member" .* must be a role name'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('malformedDefinitionsProvider')]
	public function testMalformedCompiledDefinitionsAreRejected(mixed $definitions, string $messagePattern): void
	{
		$this->expectException(\Quiote\Exception\ConfigurationException::class);
		$this->expectExceptionMessageMatches('/' . $messagePattern . '/');
		$this->assertDefinitions($definitions);
	}

	/**
	 * A top-level role compiles with `parent => null`, and the parent walk stops on `isset()`, so the
	 * key is dropped rather than carried as a null that later reads have to guard.
	 */
	public function testANullParentIsDroppedSoTheParentWalkTerminates(): void
	{
		$this->assertSame(
			['guest' => ['permissions' => ['products.list']]],
			$this->assertDefinitions(['guest' => ['parent' => null, 'permissions' => ['products.list']]])
		);
	}

	public function testARoleWithNoPermissionsKeyIsAcceptedAsHavingNone(): void
	{
		$this->assertSame(
			['guest' => ['permissions' => []]],
			$this->assertDefinitions(['guest' => []])
		);
	}

	private function assertDefinitions(mixed $definitions): mixed
	{
		$method = new ReflectionMethod(RbacSecurityUser::class, 'assertDefinitions');

		return $method->invoke(null, $definitions, '/sandbox/rbac_definitions.xml');
	}
}
?>
