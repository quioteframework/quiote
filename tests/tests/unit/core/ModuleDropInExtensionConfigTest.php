<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Quiote;

/**
 * A module registers its own plugins/middleware just by containing
 * `Config/plugins.*`/`Config/middleware.*` -- no app wiring required (see
 * Quiote::bootstrap()'s loadDeclaredExtensionConfig()). Runs in a separate
 * process: bootstrap() mutates several process-global static registries
 * (Config, PluginManager, MiddlewareConfigRegistry) that must not leak into
 * other tests.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class ModuleDropInExtensionConfigTest extends TestCase
{
    private string $appDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appDir = tempnam(sys_get_temp_dir(), 'quiote_dropin_app_');
        unlink($this->appDir);
        mkdir($this->appDir . '/Config', 0777, true);
        mkdir($this->appDir . '/Modules/DropIn/Config', 0777, true);
        mkdir($this->appDir . '/cache', 0777, true);

        file_put_contents($this->appDir . '/Config/settings.php', <<<'PHP'
<?php
return [
    'core.app_name' => 'DropInTestApp',
    'core.namespace_prefix' => 'DropInTestApp',
    'core.available' => true,
    'core.debug' => false,
    'core.use_database' => false,
    'core.use_logging' => false,
    'core.use_security' => false,
    'core.use_translation' => false,
    'core.default_context' => 'web',
];
PHP);

        file_put_contents($this->appDir . '/Modules/DropIn/Config/plugins.php', <<<'PHP'
<?php
return [
    ['class' => ModuleDropInFixturePlugin::class, 'enabled' => true],
];
PHP);

        file_put_contents($this->appDir . '/Modules/DropIn/Config/middleware.php', <<<'PHP'
<?php
return [
    ['class' => ModuleDropInFixtureMiddleware::class, 'phase' => 'pre_routing'],
];
PHP);

        Config::set('core.app_dir', $this->appDir, true, true);
        Config::set('core.config_dir', $this->appDir . '/Config', true, true);
        Config::set('core.module_dir', $this->appDir . '/Modules', true, true);
        Config::set('core.cache_dir', $this->appDir . '/cache', true, true);
        Config::set('core.system_config_dir', dirname(__DIR__, 4) . '/Quiote/Config/defaults', true, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->appDir);
        parent::tearDown();
    }

    private function removeRecursively(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeRecursively($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    public function testModuleContributedPluginAndMiddlewareAreDiscoveredAtBootstrap(): void
    {
        Quiote::bootstrap('testing');

        $this->assertContains(ModuleDropInFixturePlugin::class, Config::getArray('plugins'));
        $this->assertTrue(ModuleDropInFixturePlugin::$registered);

        $classes = array_column(MiddlewareConfigRegistry::all(), 'class');
        $this->assertContains(ModuleDropInFixtureMiddleware::class, $classes);
    }
}

#[\Quiote\Plugin\Attribute\Plugin(name: 'test/drop-in-fixture')]
final class ModuleDropInFixturePlugin implements \Quiote\Plugin\PluginInterface
{
    public static bool $registered = false;

    public function register(\Quiote\Plugin\PluginRegistrar $registrar): void
    {
        self::$registered = true;
    }
}

final class ModuleDropInFixtureMiddleware implements \Psr\Http\Server\MiddlewareInterface
{
    public function process(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Server\RequestHandlerInterface $handler
    ): \Psr\Http\Message\ResponseInterface {
        return $handler->handle($request);
    }
}
