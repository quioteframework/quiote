<?php

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
}
