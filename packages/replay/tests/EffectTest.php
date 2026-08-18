<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;

final class EffectTest extends TestCase
{
    public function testExposesItsConstructorArgumentsAsReadonlyProperties(): void
    {
        $effect = new Effect(3, EffectKind::Http, 'GET /x', ['method' => 'GET', 'uri' => '/x'], 'response-body', 1500);

        $this->assertSame(3, $effect->seq);
        $this->assertSame(EffectKind::Http, $effect->kind);
        $this->assertSame('GET /x', $effect->fingerprint);
        $this->assertSame(['method' => 'GET', 'uri' => '/x'], $effect->call);
        $this->assertSame('response-body', $effect->result);
        $this->assertSame(1500, $effect->durationMicros);
    }

    public function testDurationMicrosDefaultsToNull(): void
    {
        $effect = new Effect(0, EffectKind::Db, 'select 1', [], null);

        $this->assertNull($effect->durationMicros);
    }

    public function testResultAcceptsAnyType(): void
    {
        $effect = new Effect(0, EffectKind::Cache, 'key', [], ['nested' => ['value' => 1]]);

        $this->assertSame(['nested' => ['value' => 1]], $effect->result);
    }
}
