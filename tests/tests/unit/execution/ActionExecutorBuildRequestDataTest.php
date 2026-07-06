<?php

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Quiote\Execution\ActionExecutor;
use Quiote\Request\WebRequest;

/**
 * Happy + failure path coverage for ActionExecutor::buildRequestDataFromPsr(),
 * a self-contained static PSR-7-to-WebRequest adapter previously only
 * exercised indirectly (34% lines) through the full middleware pipeline.
 */
class ActionExecutorBuildRequestDataTest extends TestCase
{
    public function testBuildsWebRequestFromPlainGetRequestWithQueryParams(): void
    {
        $psr = (new ServerRequest('GET', '/foo'))->withQueryParams(['a' => '1']);

        $web = ActionExecutor::buildRequestDataFromPsr($psr);

        $this->assertInstanceOf(WebRequest::class, $web);
        $this->assertSame('1', $web->getParameter('a'));
    }

    public function testQueryParametersWinOverBodyParametersWithSameKey(): void
    {
        // NOTE: the source comment on `$params = $query + $body;` says "body wins", but
        // PHP's array union operator (+) actually keeps the LEFT operand on key collision,
        // so query params take precedence here -- documenting the real, current behavior.
        $psr = (new ServerRequest('POST', '/foo'))
            ->withQueryParams(['a' => 'from-query'])
            ->withParsedBody(['a' => 'from-body']);

        $web = ActionExecutor::buildRequestDataFromPsr($psr);

        $this->assertSame('from-query', $web->getParameter('a'));
    }

    public function testParsesUrlEncodedRawBodyWhenParsedBodyIsNotAnArray(): void
    {
        $psr = (new ServerRequest('POST', '/foo', ['Content-Type' => 'application/x-www-form-urlencoded']))
            ->withBody(\Nyholm\Psr7\Stream::create('name=Ada&role=admin'));

        $web = ActionExecutor::buildRequestDataFromPsr($psr);

        $this->assertSame('Ada', $web->getParameter('name'));
        $this->assertSame('admin', $web->getParameter('role'));
    }

    public function testModuleActionOutputTypeAttributesFillInWhenAbsentFromParams(): void
    {
        $psr = (new ServerRequest('GET', '/foo'))
            ->withAttribute('module', 'Stub')
            ->withAttribute('action', 'Index')
            ->withAttribute('output_type', 'json');

        $web = ActionExecutor::buildRequestDataFromPsr($psr);

        $this->assertSame('Stub', $web->getParameter('module'));
        $this->assertSame('Index', $web->getParameter('action'));
        $this->assertSame('json', $web->getParameter('output_type'));
    }

    public function testExplicitQueryParamsTakePrecedenceOverAttributeFallback(): void
    {
        $psr = (new ServerRequest('GET', '/foo'))
            ->withQueryParams(['module' => 'FromQuery'])
            ->withAttribute('module', 'FromAttribute');

        $web = ActionExecutor::buildRequestDataFromPsr($psr);

        $this->assertSame('FromQuery', $web->getParameter('module'));
    }
}
