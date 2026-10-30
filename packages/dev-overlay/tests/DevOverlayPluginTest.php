<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DevOverlay\DevOverlayMiddleware;
use Quiote\DevOverlay\DevOverlayPlugin;
use Quiote\DevOverlay\Report\ExceptionReportStoreInterface;
use Quiote\DevOverlay\Report\FileExceptionReportStore;
use Quiote\DI\Container;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Plugin\PluginManager;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Clock\SystemClock;
use Quiote\Support\Random\RandomnessInterface;
use Quiote\Support\Random\SystemRandomness;

final class DevOverlayPluginTest extends TestCase
{
    private const array KEYS = ['dev_overlay.enabled', 'dev_overlay.path', 'dev_overlay.store_path', 'dev_overlay.max_reports', 'dev_overlay.editor_url'];

    #[Before]
    #[After]
    public function resetState(): void
    {
        PluginManager::reset();
        MiddlewareCatalog::reset();
        foreach (self::KEYS as $key) {
            Config::remove($key);
        }
    }

    private function container(): Container
    {
        $container = new Container();
        $container->set(ClockInterface::class, new SystemClock());
        $container->set(RandomnessInterface::class, new SystemRandomness());
        PluginManager::configureContainer($container);

        return $container;
    }

    public function testRegistersDefaultConfig(): void
    {
        PluginManager::add(new DevOverlayPlugin());
        PluginManager::bootFromConfig();

        $this->assertTrue(Config::getBool('dev_overlay.enabled'));
        $this->assertSame('/_quiote/overlay', Config::getString('dev_overlay.path'));
        $this->assertNull(Config::getNullableString('dev_overlay.store_path'));
        $this->assertSame(50, Config::getInt('dev_overlay.max_reports'));
        $this->assertSame('', Config::getString('dev_overlay.editor_url'));
    }

    public function testBindsAFileStoreAsTheReportStore(): void
    {
        PluginManager::add(new DevOverlayPlugin());
        PluginManager::bootFromConfig();

        $this->assertInstanceOf(FileExceptionReportStore::class, $this->container()->get(ExceptionReportStoreInterface::class));
    }

    public function testRegistersTheMiddlewareWithAContextFactory(): void
    {
        PluginManager::add(new DevOverlayPlugin());
        PluginManager::bootFromConfig();

        $this->assertContains(DevOverlayMiddleware::class, MiddlewareCatalog::getAttributedCandidates());
        $factory = MiddlewareCatalog::attributedFactory(DevOverlayMiddleware::class);
        $this->assertNotNull($factory);

        $container = $this->container();
        $context = $this->createStub(Context::class);
        $context->method('getContainer')->willReturn($container);

        $this->assertInstanceOf(DevOverlayMiddleware::class, $factory($context));
    }
}
