<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Openapi\OpenApiOptions;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The `core.openapi.*` settings behind OpenApiOptions, including the two
 * `servers` shapes a settings file can plausibly use.
 */
final class OpenApiOptionsTest extends PhpUnitTestCase
{
    private const array SETTINGS = [
        'core.openapi.title',
        'core.openapi.version',
        'core.openapi.description',
        'core.openapi.servers',
        'core.openapi.exclude_routes',
        'core.openapi.modules',
        'core.openapi.problem_responses',
        'core.openapi.use_action_docblocks',
        'core.app_name',
    ];

    #[Before]
    #[After]
    public function resetSettings(): void
    {
        foreach (self::SETTINGS as $setting) {
            Config::remove($setting);
        }
    }

    public function testDefaultsWhenNothingIsConfigured(): void
    {
        $options = OpenApiOptions::fromConfig();

        $this->assertSame('API', $options->title);
        $this->assertSame('1.0.0', $options->version);
        $this->assertNull($options->description);
        $this->assertSame([], $options->servers);
        $this->assertTrue($options->problemResponses);
        $this->assertTrue($options->useActionDocblocks);
    }

    public function testTitleFallsBackToTheApplicationName(): void
    {
        Config::set('core.app_name', 'Demo App', true);

        $this->assertSame('Demo App', OpenApiOptions::fromConfig()->title);
    }

    public function testSettingsArePickedUp(): void
    {
        Config::set('core.openapi.title', 'Orders API', true);
        Config::set('core.openapi.version', '3.1.4', true);
        Config::set('core.openapi.description', 'Everything orders.', true);
        Config::set('core.openapi.exclude_routes', ['internal.*'], true);
        Config::set('core.openapi.modules', ['Orders'], true);
        Config::set('core.openapi.problem_responses', false, true);
        Config::set('core.openapi.use_action_docblocks', false, true);

        $options = OpenApiOptions::fromConfig();

        $this->assertSame('Orders API', $options->title);
        $this->assertSame('3.1.4', $options->version);
        $this->assertSame('Everything orders.', $options->description);
        $this->assertSame(['internal.*'], $options->excludeRoutes);
        $this->assertSame(['Orders'], $options->modules);
        $this->assertFalse($options->problemResponses);
        $this->assertFalse($options->useActionDocblocks);
    }

    public function testAnEmptyDescriptionSettingStaysNull(): void
    {
        Config::set('core.openapi.description', '', true);

        $this->assertNull(OpenApiOptions::fromConfig()->description);
    }

    public function testServersMayBeABareListOfUrls(): void
    {
        Config::set('core.openapi.servers', ['https://api.example.test', 'https://staging.example.test'], true);

        $this->assertSame(
            [['url' => 'https://api.example.test'], ['url' => 'https://staging.example.test']],
            OpenApiOptions::fromConfig()->servers,
        );
    }

    public function testServersMayCarryDescriptions(): void
    {
        Config::set('core.openapi.servers', [
            ['url' => 'https://api.example.test', 'description' => 'Production'],
        ], true);

        $this->assertSame(
            [['url' => 'https://api.example.test', 'description' => 'Production']],
            OpenApiOptions::fromConfig()->servers,
        );
    }

    public function testUnusableServerEntriesAreDropped(): void
    {
        $this->assertSame(
            [['url' => 'https://ok.example.test']],
            OpenApiOptions::normalizeServers([
                '',
                42,
                ['description' => 'no url here'],
                ['url' => ''],
                ['url' => 'https://ok.example.test', 'description' => ''],
            ]),
        );
    }

    public function testExcludesMatchesExactNamesAndPatterns(): void
    {
        $options = new OpenApiOptions(excludeRoutes: ['debug.dump', 'internal.*']);

        $this->assertTrue($options->excludes('debug.dump'));
        $this->assertTrue($options->excludes('internal.metrics'));
        $this->assertFalse($options->excludes('orders.list'));
    }

    public function testEverythingIsCoveredWhenNoModuleFilterIsSet(): void
    {
        $options = new OpenApiOptions();

        $this->assertTrue($options->coversModule('Anything'));
    }

    public function testModuleFilterIsCaseInsensitive(): void
    {
        $options = new OpenApiOptions(modules: ['orders']);

        $this->assertTrue($options->coversModule('Orders'));
        $this->assertFalse($options->coversModule('Invoices'));
    }
}
