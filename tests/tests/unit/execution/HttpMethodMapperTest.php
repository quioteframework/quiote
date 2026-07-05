<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use Quiote\Config\Config;
use Quiote\Execution\HttpMethodMapper;

class HttpMethodMapperTest extends TestCase
{
    #[After]
    public function tearDownConfig(): void
    {
        Config::remove('routing.http_method_map');
    }

    public function testDefaultMappingIsUnaffectedWithNoConfig(): void
    {
        $this->assertSame('read', HttpMethodMapper::toActionMethod('GET'));
        $this->assertSame('write', HttpMethodMapper::toActionMethod('post'));
        $this->assertSame('update', HttpMethodMapper::toActionMethod('PUT'));
        $this->assertSame('update', HttpMethodMapper::toActionMethod('PATCH'));
        $this->assertSame('remove', HttpMethodMapper::toActionMethod('DELETE'));
        $this->assertSame('read', HttpMethodMapper::toActionMethod('UNKNOWN'));
    }

    public function testConfigCanOverrideAnExistingVerb(): void
    {
        Config::set('routing.http_method_map', ['PATCH' => 'write']);
        $this->assertSame('write', HttpMethodMapper::toActionMethod('PATCH'));
        // Untouched verbs keep the default mapping.
        $this->assertSame('remove', HttpMethodMapper::toActionMethod('DELETE'));
    }

    public function testConfigCanAddACustomVerb(): void
    {
        Config::set('routing.http_method_map', ['LOCK' => 'lock']);
        $this->assertSame('lock', HttpMethodMapper::toActionMethod('LOCK'));
        $this->assertSame('lock', HttpMethodMapper::toActionMethod('lock'), 'verb matching is case-insensitive');
    }

    public function testConfigKeysAndValuesAreCaseNormalized(): void
    {
        Config::set('routing.http_method_map', ['patch' => 'REMOVE']);
        $this->assertSame('remove', HttpMethodMapper::toActionMethod('PATCH'));
    }

    public function testNonArrayConfigValueThrows(): void
    {
        Config::set('routing.http_method_map', 'not-an-array');
        $this->expectException(Quiote\Exception\ConfigurationException::class);
        HttpMethodMapper::toActionMethod('GET');
    }
}
