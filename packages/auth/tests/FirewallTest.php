<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\EntryPoint\HttpChallengeEntryPoint;
use Quiote\Security\Auth\Firewall;
use Quiote\Security\Auth\FirewallMap;

class FirewallTest extends TestCase
{
	private function firewall(string $name, string $pattern, bool $stateless = false, bool $sessionless = false): Firewall
	{
		return new Firewall($name, $pattern, [], new HttpChallengeEntryPoint(), $stateless, $sessionless);
	}

	public function testMatchesReturnsTrueWhenPathMatchesThePattern(): void
	{
		$firewall = $this->firewall('api', '^/api/');

		$this->assertTrue($firewall->matches('/api/users'));
		$this->assertFalse($firewall->matches('/web/users'));
	}

	public function testGettersExposeConstructorValues(): void
	{
		$firewall = $this->firewall('api', '^/api/', stateless: true, sessionless: true);

		$this->assertSame('api', $firewall->getName());
		$this->assertTrue($firewall->isStateless());
		$this->assertTrue($firewall->isSessionless());
		$this->assertSame([], $firewall->getAuthenticators());
	}

	public function testFirewallMapMatchesInDeclarationOrder(): void
	{
		$api = $this->firewall('api', '^/api/');
		$main = $this->firewall('main', '^/');

		$map = new FirewallMap([$api, $main]);

		$this->assertSame($api, $map->match('/api/users'));
		$this->assertSame($main, $map->match('/dashboard'));
	}

	public function testFirewallMapReturnsNullWhenNothingMatches(): void
	{
		$map = new FirewallMap([$this->firewall('api', '^/api/')]);

		$this->assertNull($map->match('/dashboard'));
	}

	public function testFirewallMapAllReturnsEveryFirewall(): void
	{
		$api = $this->firewall('api', '^/api/');
		$main = $this->firewall('main', '^/');

		$map = new FirewallMap([$api, $main]);

		$this->assertSame([$api, $main], $map->all());
	}

	// --- pattern validation: an unusable pattern is an unprotected surface ---

	public function testUnanchoredPatternIsRejected(): void
	{
		// `/admin` unanchored also matches `/public/admin-notes`, and because the
		// first matching firewall wins that can place a protected surface under the
		// wrong firewall. Rejected rather than silently anchored for them.
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/unanchored/');

		$this->firewall('admin', '/admin');
	}

	public function testInvalidPatternIsRejected(): void
	{
		// preg_match() returns false on a bad pattern, which the `=== 1` test reads
		// as "no match" -- so a typo would mean the firewall never matches and
		// everything it was meant to protect is unauthenticated.
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/not a valid PCRE/');

		$this->firewall('broken', '^/admin(');
	}

	// --- path normalization: the firewall must not be evadable by encoding ---

	public function testMatchesTraversalThatWouldDispatchElsewhere(): void
	{
		$firewall = $this->firewall('admin', '^/admin');

		// Whether these reach the admin action depends on what the proxy in front
		// normalized. The firewall must cover them either way.
		$this->assertTrue($firewall->matches('/admin'));
		$this->assertTrue($firewall->matches('/api/../admin'));
		$this->assertTrue($firewall->matches('/api/%2e%2e/admin'));
		$this->assertTrue($firewall->matches('/api/%252e%252e/admin'), 'double-encoded traversal too');
		$this->assertTrue($firewall->matches('//admin'));
		$this->assertTrue($firewall->matches('/./admin'));
	}

	public function testNormalizationDoesNotInventMatches(): void
	{
		// `^/admin/` rather than `^/admin`: the anchor pins the start, but a prefix
		// pattern still matches any continuation, so `^/admin` covering
		// `/admin-notes` is correct and intended. The trailing slash is how you say
		// "the /admin/ subtree".
		$firewall = $this->firewall('admin', '^/admin/');

		$this->assertFalse($firewall->matches('/public'));
		$this->assertFalse($firewall->matches('/admin-notes/reports'));
		$this->assertFalse($firewall->matches('/admin-notes/../public'), 'normalizing must not pull an unrelated path in');
		$this->assertFalse($firewall->matches('/'));
	}

	public function testTrailingSlashPatternStillMatchesTheBarePrefix(): void
	{
		$firewall = $this->firewall('api', '^/api/');

		$this->assertTrue($firewall->matches('/api/users'));
		$this->assertTrue($firewall->matches('/api/'), 'a meaningful trailing slash survives normalization');
		$this->assertTrue($firewall->matches('/api//users'));
	}

	#[DataProvider('canonicalizeCases')]
	public function testCanonicalize(string $raw, string $expected): void
	{
		$this->assertSame($expected, Firewall::canonicalize($raw));
	}

	/** @return array<string, array{string, string}> */
	public static function canonicalizeCases(): array
	{
		return [
			'already canonical'      => ['/admin/users', '/admin/users'],
			'duplicate slashes'      => ['//admin///users', '/admin/users'],
			'dot segments'           => ['/admin/./users', '/admin/users'],
			'dotdot segments'        => ['/api/../admin', '/admin'],
			'encoded dotdot'         => ['/api/%2e%2e/admin', '/admin'],
			'double encoded dotdot'  => ['/api/%252e%252e/admin', '/admin'],
			'encoded slash'          => ['/admin%2Fusers', '/admin/users'],
			'backslash separator'    => ['/admin\\users', '/admin/users'],
			'above root is clamped'  => ['/../../etc/passwd', '/etc/passwd'],
			'trailing slash kept'    => ['/admin/', '/admin/'],
			'root'                   => ['/', '/'],
			'empty'                  => ['', '/'],
		];
	}
}
