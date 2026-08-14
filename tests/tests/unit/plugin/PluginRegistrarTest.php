<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Exception\Rendering\ExceptionRenderer;
use Quiote\Exception\Rendering\ExceptionRendererRegistry;
use Quiote\Plugin\PluginRegistrar;
use Nyholm\Psr7\Response;

final class PluginRegistrarTest extends TestCase
{
    protected function tearDown(): void
    {
        ExceptionRendererRegistry::reset();
    }

    public function testSafeExceptionRendererRoutesToTheRegistry(): void
    {
        $registrar = new PluginRegistrar('test-plugin');

        $registrar->safeExceptionRenderer(static fn(): ExceptionRenderer => new PluginRegistrarStubRenderer());

        $this->assertTrue(ExceptionRendererRegistry::hasSafeRenderer());
        $this->assertInstanceOf(PluginRegistrarStubRenderer::class, ExceptionRendererRegistry::safeRenderer());
    }

    public function testSafeExceptionRendererReturnsSelfForChaining(): void
    {
        $registrar = new PluginRegistrar('test-plugin');

        $result = $registrar->safeExceptionRenderer(static fn(): ExceptionRenderer => new PluginRegistrarStubRenderer());

        $this->assertSame($registrar, $result);
    }
}

final class PluginRegistrarStubRenderer implements ExceptionRenderer
{
    public function render(Throwable $e, ServerRequestInterface $request, int $status, ?string $correlationId): ResponseInterface
    {
        return new Response($status, [], 'stub');
    }
}
