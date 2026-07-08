<?php

use PHPUnit\Framework\TestCase;
use Quiote\Middleware\SessionMiddleware;

/**
 * Covers SessionMiddleware::parseCookieHeader() in isolation (via reflection) since the
 * public process() method requires a fully wired Controller/Context/Storage stack.
 *
 * This guards against a real latent bug: preg_split() is typed to return list<string>|false,
 * and the previous code fed its result straight into a foreach without checking for false,
 * which PHPStan correctly flagged as a foreach.nonIterable error.
 */
final class SessionMiddlewareCookieParsingTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function parse(string $cookieStr): array
    {
        $method = new ReflectionMethod(SessionMiddleware::class, 'parseCookieHeader');
        /** @var array<string, string> $result */
        $result = $method->invoke(null, $cookieStr);
        return $result;
    }

    public function testHappyPathParsesMultipleCookiePairs(): void
    {
        $result = $this->parse('a=1; b=two; c=three%20value');
        $this->assertSame(['a' => '1', 'b' => 'two', 'c' => 'three value'], $result);
    }

    public function testEmptyHeaderYieldsNoCookies(): void
    {
        $this->assertSame([], $this->parse(''));
    }

    public function testPairsWithoutEqualsSignAreSkipped(): void
    {
        // "malformed" segment with no '=' must not throw and must be skipped.
        $result = $this->parse('a=1; malformed; b=2');
        $this->assertSame(['a' => '1', 'b' => '2'], $result);
    }

    public function testEmptyKeyIsSkipped(): void
    {
        $result = $this->parse('=novalue; a=1');
        $this->assertSame(['a' => '1'], $result);
    }
}
