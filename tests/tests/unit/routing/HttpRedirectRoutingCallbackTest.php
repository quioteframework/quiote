<?php

use PHPUnit\Framework\TestCase;
use Quiote\Context;
use Quiote\Request\WebRequest;
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
        $this->assertSame('https://example.com/redirected', $redirect['location']);
    }

    public function testRedirectWithDiscretePartsAndUnparsableRequestUrlFallsBackToPartsOnly(): void
    {
        $context = Context::getInstance('http_redirect_callback_test_malformed');
        // A URL containing an invalid port makes parse_url() return false; the
        // callback must not blow up on array_merge(false, ...) and should still
        // produce a redirect built purely from the configured parts.
        $context->setRequest($this->makeUnparsableUrlRequest('http://example.com:-1/original'));

        $callback = new HttpRedirectRoutingCallback(['scheme' => 'https', 'host' => 'redirected.example', 'path' => '/target']);
        $route = [];
        $callback->initialize($context, $route);

        $parameters = [];
        $response = $callback->onMatched($parameters);

        $this->assertInstanceOf(WebResponse::class, $response);
        $redirect = $response->getRedirect();
        $this->assertNotNull($redirect);
        $this->assertSame('https://redirected.example/target', $redirect['location']);
    }

    private function makeFakeRequest(string $url): WebRequest
    {
        return new WebRequest('GET', $url);
    }

    /**
     * A WebRequest cannot be constructed with a genuinely unparsable URL --
     * Nyholm's URI validation rejects it before the object exists. Subclass
     * WebRequest and override getUrl() to return the raw string directly, so
     * the callback's parse_url() fallback path can be exercised.
     */
    private function makeUnparsableUrlRequest(string $url): WebRequest
    {
        return new class($url) extends WebRequest {
            public function __construct(private readonly string $rawUrl)
            {
                parent::__construct('GET', 'http://placeholder.invalid/');
            }

            #[\Override]
            public function getUrl(): string
            {
                return $this->rawUrl;
            }
        };
    }
}
