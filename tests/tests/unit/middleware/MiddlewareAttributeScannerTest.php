<?php

use PHPUnit\Framework\TestCase;
use Quiote\Middleware\Compiler\MiddlewareAttributeScanner;
use Quiote\Middleware\Attribute\Middleware;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quiote\Support\Compiler\Diagnostic;

#[Middleware(phase: 'pre', priority: 5, after: 'ScannerFixtureA')]
final class ScannerFixtureB implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

final class ScannerFixtureA implements MiddlewareInterface
{
    // Deliberately no #[Middleware] attribute — must be skipped by the scanner.
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

final class ScannerFixtureNotMiddleware
{
}

class MiddlewareAttributeScannerTest extends TestCase
{
    public function testSkipsClassesWithoutTheAttribute(): void
    {
        $scanner = new MiddlewareAttributeScanner();
        $definitions = $scanner->scan([ScannerFixtureA::class, ScannerFixtureB::class]);

        $this->assertCount(1, $definitions);
        $this->assertSame(ScannerFixtureB::class, $definitions[0]->fqcn);
        $this->assertSame('pre', $definitions[0]->phase);
        $this->assertSame(5, $definitions[0]->priority);
        $this->assertSame('ScannerFixtureA', $definitions[0]->after);
    }

    public function testNonMiddlewareCandidateProducesErrorDiagnostic(): void
    {
        $scanner = new MiddlewareAttributeScanner();
        $scanner->scan([ScannerFixtureNotMiddleware::class]);

        $diagnostics = $scanner->getDiagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame(Diagnostic::SEVERITY_ERROR, $diagnostics[0]->severity);
        $this->assertSame(MiddlewareAttributeScanner::CODE_NOT_A_MIDDLEWARE, $diagnostics[0]->code);
    }

    public function testMissingClassProducesErrorDiagnostic(): void
    {
        $scanner = new MiddlewareAttributeScanner();
        $definitions = $scanner->scan(['Totally\\Nonexistent\\ClassName']);

        $this->assertSame([], $definitions);
        $diagnostics = $scanner->getDiagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame(MiddlewareAttributeScanner::CODE_CLASS_NOT_FOUND, $diagnostics[0]->code);
    }

    public function testDuplicateCandidateProducesWarningDiagnostic(): void
    {
        $scanner = new MiddlewareAttributeScanner();
        $definitions = $scanner->scan([ScannerFixtureB::class, ScannerFixtureB::class]);

        $this->assertCount(1, $definitions);
        $diagnostics = $scanner->getDiagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame(Diagnostic::SEVERITY_WARNING, $diagnostics[0]->severity);
        $this->assertSame(MiddlewareAttributeScanner::CODE_DUPLICATE_CANDIDATE, $diagnostics[0]->code);
    }
}
