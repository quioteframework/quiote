<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Exception\Rendering\ExceptionRenderer;
use Quiote\Exception\Rendering\ExceptionRendererRegistry;
use Nyholm\Psr7\Response;

final class ExceptionRendererRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ExceptionRendererRegistry::reset();
    }

    public function testSafeRendererIsNullUntilRegistered(): void
    {
        $this->assertFalse(ExceptionRendererRegistry::hasSafeRenderer());
        $this->assertNull(ExceptionRendererRegistry::safeRenderer());
    }

    public function testSetSafeRendererIsHonoured(): void
    {
        ExceptionRendererRegistry::setSafeRenderer(static fn(): ExceptionRenderer => new StubExceptionRenderer());

        $this->assertTrue(ExceptionRendererRegistry::hasSafeRenderer());
        $this->assertInstanceOf(StubExceptionRenderer::class, ExceptionRendererRegistry::safeRenderer());
    }

    public function testSetSafeRendererIsSetIfAbsentFirstRegistrationWins(): void
    {
        ExceptionRendererRegistry::setSafeRenderer(static fn(): ExceptionRenderer => new StubExceptionRenderer());
        ExceptionRendererRegistry::setSafeRenderer(static fn(): ExceptionRenderer => new OtherStubExceptionRenderer());

        $this->assertInstanceOf(StubExceptionRenderer::class, ExceptionRendererRegistry::safeRenderer());
    }

    public function testResetClearsBothDeveloperAndSafeRenderer(): void
    {
        ExceptionRendererRegistry::setDeveloperRenderer(static fn(): ExceptionRenderer => new StubExceptionRenderer());
        ExceptionRendererRegistry::setSafeRenderer(static fn(): ExceptionRenderer => new StubExceptionRenderer());

        ExceptionRendererRegistry::reset();

        $this->assertFalse(ExceptionRendererRegistry::hasDeveloperRenderer());
        $this->assertFalse(ExceptionRendererRegistry::hasSafeRenderer());
    }
}

final class StubExceptionRenderer implements ExceptionRenderer
{
    public function render(Throwable $e, ServerRequestInterface $request, int $status, ?string $correlationId): ResponseInterface
    {
        return new Response($status, [], 'stub');
    }
}

final class OtherStubExceptionRenderer implements ExceptionRenderer
{
    public function render(Throwable $e, ServerRequestInterface $request, int $status, ?string $correlationId): ResponseInterface
    {
        return new Response($status, [], 'other');
    }
}
