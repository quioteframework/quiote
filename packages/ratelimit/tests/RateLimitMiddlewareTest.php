<?php

declare(strict_types=1);

use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Config\Config;
use Quiote\Http\ProblemDetails;
use Quiote\Security\RateLimit\RateLimitMiddleware;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimitMiddlewareTest extends TestCase
{
    private const CONFIG_KEYS = [
        'ratelimit.http.enabled',
        'ratelimit.http.max_requests',
        'ratelimit.http.window',
        'ratelimit.http.policy',
        'ratelimit.http.trust_forwarded_for',
    ];

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CONFIG_KEYS as $key) {
            $this->originalConfig[$key] = Config::has($key) ? Config::get($key) : null;
        }
        Config::set('ratelimit.http.enabled', true);
        Config::set('ratelimit.http.window', '1 hour');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalConfig as $key => $value) {
            if ($value === null) {
                Config::remove($key);
            } else {
                Config::set($key, $value);
            }
        }
        parent::tearDown();
    }

    private function okHandler(): RateLimitRecordingHandler
    {
        return new RateLimitRecordingHandler();
    }

    private function requestFrom(string $ip): ServerRequestInterface
    {
        return new ServerRequest('GET', 'http://localhost/x', [], null, '1.1', ['REMOTE_ADDR' => $ip]);
    }

    public function testDisabledConfigBypassesMiddleware(): void
    {
        Config::set('ratelimit.http.enabled', false);
        Config::set('ratelimit.http.max_requests', 1);
        $mw = new RateLimitMiddleware(new InMemoryStorage());
        $handler = $this->okHandler();

        $mw->process($this->requestFrom('1.2.3.4'), $handler);
        $resp = $mw->process($this->requestFrom('1.2.3.4'), $handler);

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(2, $handler->calls);
    }

    public function testAllowsUpToLimitThenRejectsWith429(): void
    {
        Config::set('ratelimit.http.max_requests', 2);
        $mw = new RateLimitMiddleware(new InMemoryStorage());
        $handler = $this->okHandler();

        $first = $mw->process($this->requestFrom('1.2.3.4'), $handler);
        $second = $mw->process($this->requestFrom('1.2.3.4'), $handler);
        $third = $mw->process($this->requestFrom('1.2.3.4'), $handler);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(429, $third->getStatusCode());
        $this->assertSame(2, $handler->calls, 'the rejected request must never reach the handler');
    }

    public function testRejectionCarriesRetryAfterAndProblemDetailsBody(): void
    {
        Config::set('ratelimit.http.max_requests', 1);
        $mw = new RateLimitMiddleware(new InMemoryStorage());
        $handler = $this->okHandler();

        $mw->process($this->requestFrom('1.2.3.4'), $handler);
        $resp = $mw->process($this->requestFrom('1.2.3.4'), $handler);

        $this->assertSame(429, $resp->getStatusCode());
        $this->assertSame(ProblemDetails::MEDIA_TYPE, $resp->getHeaderLine('Content-Type'));
        $this->assertGreaterThan(0, (int) $resp->getHeaderLine('Retry-After'));

        $body = json_decode((string) $resp->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame(429, $body['status']);
    }

    public function testDifferentClientsAreIsolated(): void
    {
        Config::set('ratelimit.http.max_requests', 1);
        $mw = new RateLimitMiddleware(new InMemoryStorage());
        $handler = $this->okHandler();

        $a = $mw->process($this->requestFrom('1.1.1.1'), $handler);
        $b = $mw->process($this->requestFrom('2.2.2.2'), $handler);

        $this->assertSame(200, $a->getStatusCode());
        $this->assertSame(200, $b->getStatusCode(), 'a different client must not share the first client\'s bucket');
    }

    public function testTrustsForwardedForOnlyWhenExplicitlyEnabled(): void
    {
        Config::set('ratelimit.http.max_requests', 1);
        Config::set('ratelimit.http.trust_forwarded_for', true);
        $mw = new RateLimitMiddleware(new InMemoryStorage());
        $handler = $this->okHandler();

        $req = $this->requestFrom('9.9.9.9')->withHeader('X-Forwarded-For', '3.3.3.3, 10.0.0.1');
        $first = $mw->process($req, $handler);
        // Same forwarded IP, different REMOTE_ADDR -> still the same bucket.
        $second = $mw->process($this->requestFrom('8.8.8.8')->withHeader('X-Forwarded-For', '3.3.3.3'), $handler);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(429, $second->getStatusCode());
    }
}

final class RateLimitRecordingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $r): ResponseInterface
    {
        $this->calls++;
        return new Psr7Response(200);
    }
}
