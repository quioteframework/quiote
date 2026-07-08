<?php

use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Response\WebResponse;
use Quiote\Routing\HttpRedirectRoutingCallback;

/**
 * Test class for HttpRedirectRoutingCallback.
 * Covers the base-URL derivation path used when redirecting via discrete
 * scheme/host/etc. parameters instead of "route" or "url".
 */
class HttpRedirectRoutingCallbackTest extends TestCase
{
    public function testRedirectWithDiscretePartsAndParsableRequestUrlMergesHostIntoRedirect(): void
    {
        $context = Context::getInstance('http_redirect_callback_test_happy');
        $context->setRequest($this->makeFakeRequest('https://example.com/original/path'));

        $callback = new HttpRedirectRoutingCallback(['path' => '/redirected']);
        $route = [];
        $callback->initialize($context, $route);

        $parameters = [];
        $response = $callback->onMatched($parameters);

        $this->assertInstanceOf(WebResponse::class, $response);
        $redirect = $response->getRedirect();
        $this->assertNotNull($redirect);
        $this->assertSame('https://example.com/redirected', (string) $redirect['location']);
    }

    public function testRedirectWithDiscretePartsAndUnparsableRequestUrlFallsBackToPartsOnly(): void
    {
        $context = Context::getInstance('http_redirect_callback_test_malformed');
        // A URL containing an invalid port makes parse_url() return false; the
        // callback must not blow up on array_merge(false, ...) and should still
        // produce a redirect built purely from the configured parts.
        $context->setRequest($this->makeFakeRequest('http://example.com:-1/original'));

        $callback = new HttpRedirectRoutingCallback(['scheme' => 'https', 'host' => 'redirected.example', 'path' => '/target']);
        $route = [];
        $callback->initialize($context, $route);

        $parameters = [];
        $response = $callback->onMatched($parameters);

        $this->assertInstanceOf(WebResponse::class, $response);
        $redirect = $response->getRedirect();
        $this->assertNotNull($redirect);
        $this->assertSame('https://redirected.example/target', (string) $redirect['location']);
    }

    private function makeFakeRequest(string $url): object
    {
        return new class($url) {
            public function __construct(private readonly string $url)
            {
            }

            public function getUrl(): string
            {
                return $this->url;
            }

            public function getProtocol(): string
            {
                return 'HTTP/1.1';
            }
        };
    }
}
