<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\DevOverlay\DevOverlayConfig;

final class DevOverlayConfigTest extends TestCase
{
    private ?bool $developerExceptions = null;

    #[Before]
    public function rememberDeveloperExceptions(): void
    {
        $this->developerExceptions = Config::has('core.developer_exceptions') ? Config::getBool('core.developer_exceptions') : null;
    }

    #[After]
    public function restoreConfig(): void
    {
        foreach (['dev_overlay.enabled', 'dev_overlay.path', 'dev_overlay.editor_url'] as $key) {
            Config::remove($key);
        }
        Config::remove('core.developer_exceptions');
        if ($this->developerExceptions !== null) {
            Config::set('core.developer_exceptions', $this->developerExceptions, true, false);
        }
    }

    public function testInactiveWithoutDeveloperExceptions(): void
    {
        Config::set('core.developer_exceptions', false, true, false);
        Config::set('dev_overlay.enabled', true, true, false);

        $this->assertFalse(DevOverlayConfig::fromConfig()->active);
    }

    public function testActiveWithDeveloperExceptionsByDefault(): void
    {
        Config::set('core.developer_exceptions', true, true, false);

        $this->assertTrue(DevOverlayConfig::fromConfig()->active);
    }

    public function testEnabledFalseSwitchesItOffEvenWithDeveloperExceptions(): void
    {
        Config::set('core.developer_exceptions', true, true, false);
        Config::set('dev_overlay.enabled', false, true, false);

        $this->assertFalse(DevOverlayConfig::fromConfig()->active);
    }

    public function testThePathIsNormalised(): void
    {
        Config::set('dev_overlay.path', 'dev/overlay/', true, false);
        $this->assertSame('/dev/overlay', DevOverlayConfig::fromConfig()->path);

        Config::set('dev_overlay.path', '/', true, false);
        $this->assertSame('/_quiote/overlay', DevOverlayConfig::fromConfig()->path);
    }

    public function testCarriesTheEditorUrl(): void
    {
        Config::set('dev_overlay.editor_url', 'vscode://file/{file}:{line}', true, false);

        $this->assertSame('vscode://file/{file}:{line}', DevOverlayConfig::fromConfig()->editorUrl);
    }
}
