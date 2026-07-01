<?php

use PHPUnit\Framework\TestCase;
use Quiote\Http\SimpleUri;
use Psr\Http\Message\UriInterface;

class SimpleUriTest extends TestCase
{
    public function testParseFullUriRoundTrip(): void
    {
        $u = new SimpleUri('https://user:pass@example.com:8443/foo/bar?x=1&y=2#frag');
        $this->assertSame('https', $u->getScheme());
        $this->assertSame('user:pass@example.com:8443', $u->getAuthority());
        $this->assertSame('user:pass', $u->getUserInfo());
        $this->assertSame('example.com', $u->getHost());
        $this->assertSame(8443, $u->getPort());
        $this->assertSame('/foo/bar', $u->getPath());
        $this->assertSame('x=1&y=2', $u->getQuery());
        $this->assertSame('frag', $u->getFragment());
        $this->assertSame('https://user:pass@example.com:8443/foo/bar?x=1&y=2#frag', (string)$u);
    }

    public function testRelativeUri(): void
    {
        $u = new SimpleUri('/only/path?x=5#z');
        $this->assertSame('', $u->getScheme());
        $this->assertSame('/only/path', $u->getPath());
        $this->assertSame('x=5', $u->getQuery());
        $this->assertSame('z', $u->getFragment());
        $this->assertSame('/only/path?x=5#z', (string)$u);
    }

    public function testImmutabilityOnMutation(): void
    {
        $base = new SimpleUri('http://a');
        $mod = $base->withScheme('https')->withHost('b.example')->withPort(8080)->withPath('/p')->withQuery('?q=1')->withFragment('#f')->withUserInfo('u','pw');
        $this->assertSame('http://a', (string)$base, 'Original should remain unchanged');
        $this->assertSame('https://u:pw@b.example:8080/p?q=1#f', (string)$mod);
    }

    public function testWithQueryStripsLeadingQuestionMark(): void
    {
        $u = (new SimpleUri('http://h'))->withQuery('?a=1');
        $this->assertSame('a=1', $u->getQuery());
        $this->assertSame('http://h?a=1', (string)$u);
    }

    public function testWithFragmentStripsHash(): void
    {
        $u = (new SimpleUri('http://h'))->withFragment('#frag');
        $this->assertSame('frag', $u->getFragment());
        $this->assertSame('http://h#frag', (string)$u);
    }

    public function testEmptyComponents(): void
    {
        $u = new SimpleUri('http://example.com');
        $this->assertSame('', $u->getQuery());
        $this->assertSame('', $u->getFragment());
        $this->assertSame('', $u->getUserInfo());
    }
}
