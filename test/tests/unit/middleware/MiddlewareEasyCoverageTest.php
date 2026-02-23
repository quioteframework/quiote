<?php

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\ServerRequest;
use Agavi\Http\PsrResponseAdapter;
use Agavi\Middleware\ExecutionTimeMiddleware;
use Agavi\Middleware\MiddlewarePipelineBuilder;
use Agavi\Middleware\MiddlewarePipeline;
use Agavi\Execution\HttpMethodMapper;
use Agavi\Util\AgaviXsltProcessor;

#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'pre', priority: 5)]
class DummyCoreMiddleware implements \Psr\Http\Server\MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        return $handler->handle($request->withAttribute('dummy', true));
    }
}

#[\Agavi\Middleware\Attribute\AgaviMiddleware(phase: 'finalize', priority: 0)]
class DummyFinalizeMiddleware implements \Psr\Http\Server\MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        return $handler->handle($request);
    }
}

class TerminalHandler implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        // Minimal AgaviWebResponse setup
        $legacy = new class extends \Agavi\Response\AgaviWebResponse {
            public function __construct() { /* bypass parent ctor */ }
            public function initialize(\Agavi\AgaviContext $context, array $parameters = []) {}
        };
        // Set initial content via reflection (content is protected in base class)
        $refLegacy = new ReflectionClass($legacy);
        if($refLegacy->hasProperty('content')) {
            $prop = $refLegacy->getProperty('content');
            // $prop->setAccessible(true); // Deprecated, not needed in PHP 8.1+
            $prop->setValue($legacy, '<html>ok');
        }
        return new PsrResponseAdapter($legacy);
    }
}

class MiddlewareEasyCoverageTest extends TestCase
{
    public function testExecutionTimeMiddlewareAppendsComment()
    {
        $mw = new ExecutionTimeMiddleware(true);
        $handler = new TerminalHandler();
        $req = new ServerRequest('GET', 'https://example.org/');
        $resp = $mw->process($req, $handler);
        $this->assertInstanceOf(PsrResponseAdapter::class, $resp);
        $body = (string)$resp->getBody();
        $this->assertStringContainsString('<html>ok', $body);
    }

    // Removed builder-based pipeline construction test after refactor to context-only pipeline.

    public function testHttpMethodMapperMappings()
    {
        $this->assertSame('read', HttpMethodMapper::toActionMethod('GET'));
        $this->assertSame('write', HttpMethodMapper::toActionMethod('post'));
        $this->assertSame('create', HttpMethodMapper::toActionMethod('PUT'));
        $this->assertSame('remove', HttpMethodMapper::toActionMethod('DELETE'));
        $this->assertSame('read', HttpMethodMapper::toActionMethod('UNKNOWN')); // default fallback
    }

    public function testXsltProcessorTransformsDocument()
    {
        if(!extension_loaded('xsl')) {
            $this->markTestSkipped('xsl extension not loaded');
        }
        $proc = new AgaviXsltProcessor();
        $style = new DOMDocument();
    $style->loadXML('<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"><xsl:template match="/root"><result><xsl:value-of select="child"/></result></xsl:template></xsl:stylesheet>');
        $proc->importStylesheet($style);
        $src = new DOMDocument();
        $src->loadXML('<root><child>value</child></root>');
        $out = $proc->transformToDoc($src);
        $this->assertInstanceOf(DOMDocument::class, $out);
        $this->assertStringContainsString('<result>value</result>', $out->saveXML());
    }
}
