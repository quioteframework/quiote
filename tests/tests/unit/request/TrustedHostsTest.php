<?php

use Quiote\Request\TrustedHosts;
use Quiote\Testing\UnitTestCase;

/**
 * The `core.trusted_hosts` rule, tested directly rather than through whichever
 * caller happens to invoke it.
 *
 * It was previously inlined in {@see \Quiote\Request\RequestUrl} and had no
 * coverage of its own, which is part of how a second host-resolution path
 * (Routing's `$_SERVER` fallback) came to skip it entirely without anything
 * failing.
 */
class TrustedHostsTest extends UnitTestCase
{
    public function testAnEmptyListAppliesNoRestriction(): void
    {
        // The pre-setting default. Deployments that never configured this must
        // keep working exactly as before.
        $this->assertSame('anything.example', TrustedHosts::filterAgainst('anything.example', []));
    }

    public function testAnExactMatchIsAccepted(): void
    {
        $this->assertSame(
            'app.example.com',
            TrustedHosts::filterAgainst('app.example.com', ['app.example.com', 'other.example.com']),
        );
    }

    public function testHostnameMatchingIsCaseInsensitive(): void
    {
        // Hostnames are case-insensitive on the wire, so a case-sensitive
        // comparison would canonicalize away a perfectly legitimate Host.
        $this->assertSame('APP.example.com', TrustedHosts::filterAgainst('APP.example.com', ['app.example.com']));
    }

    public function testANonMatchingHostIsReplacedWithTheFirstLiteral(): void
    {
        // The poisoning case: the attacker's Host must not survive into a
        // generated URL. There is no response to fail into here, so it
        // canonicalizes to a host the operator actually named.
        $this->assertSame(
            'app.example.com',
            TrustedHosts::filterAgainst('evil.example', ['app.example.com', 'admin.example.com']),
        );
    }

    public function testASubstringOfATrustedHostIsNotTrusted(): void
    {
        // Exact comparison, not substring/suffix: neither a host that contains a
        // trusted one nor one that is contained by it may pass.
        $this->assertSame('app.example.com', TrustedHosts::filterAgainst('app.example.com.evil.test', ['app.example.com']));
        $this->assertSame('app.example.com', TrustedHosts::filterAgainst('example.com', ['app.example.com']));
    }

    public function testARegexEntryMatches(): void
    {
        $this->assertSame(
            'tenant-7.example.com',
            TrustedHosts::filterAgainst('tenant-7.example.com', ['/^tenant-\d+\.example\.com$/']),
        );
    }

    public function testANonMatchingRegexFallsBackToTheFirstLiteral(): void
    {
        $this->assertSame(
            'app.example.com',
            TrustedHosts::filterAgainst('evil.example', ['/^tenant-\d+\.example\.com$/', 'app.example.com']),
        );
    }

    public function testAnUncompilableRegexDoesNotMatchAndDoesNotThrow(): void
    {
        // A typo in a pattern must close the allow-list, never open it: treating a
        // preg_match() failure as a match would let any host through. The entry is
        // delimited, so it is read as a regex; the character class is unclosed, so
        // it does not compile.
        $this->assertSame(
            'app.example.com',
            TrustedHosts::filterAgainst('evil.example', ['/[unclosed/', 'app.example.com']),
        );
    }

    public function testAnUndelimitedEntryIsALiteralNotABrokenRegex(): void
    {
        // Only a `/`-wrapped entry is a pattern. Something that merely starts with
        // a slash is an ordinary (if odd) literal hostname, and being the first
        // literal makes it the canonicalization target.
        $this->assertSame('/unterminated', TrustedHosts::filterAgainst('evil.example', ['/unterminated']));
    }

    public function testARegexOnlyListLeavesANonMatchingHostAlone(): void
    {
        // Nothing literal to canonicalize to. Returning the host unchanged is the
        // only option that does not invent a hostname the operator never named.
        $this->assertSame('evil.example', TrustedHosts::filterAgainst('evil.example', ['/^app\.example\.com$/']));
    }

    public function testNonStringAndEmptyEntriesAreSkipped(): void
    {
        $this->assertSame(
            'app.example.com',
            TrustedHosts::filterAgainst('evil.example', ['', null, 42, 'app.example.com']),
        );
    }

    public function testAnEmptyHostIsReturnedUnchanged(): void
    {
        $this->assertSame('', TrustedHosts::filterAgainst('', ['app.example.com']));
        $this->assertSame('', TrustedHosts::filter(''));
    }

    public function testFilterReadsTheConfiguredList(): void
    {
        $previous = \Quiote\Config\Config::getArray('core.trusted_hosts', []);
        \Quiote\Config\Config::set('core.trusted_hosts', ['app.example.com']);

        try {
            $this->assertSame('app.example.com', TrustedHosts::filter('app.example.com'));
            $this->assertSame('app.example.com', TrustedHosts::filter('evil.example'));
        } finally {
            \Quiote\Config\Config::set('core.trusted_hosts', $previous);
        }
    }
}
