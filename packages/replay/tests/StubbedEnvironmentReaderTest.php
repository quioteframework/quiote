<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Replay\StubbedEnvironmentReader;

final class StubbedEnvironmentReaderTest extends TestCase
{
    public function testARecordedSetVariableReplaysCorrectly(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Env, 'MY_VAR', ['name' => 'MY_VAR'], 'some-value'),
        ]);
        $reader = new StubbedEnvironmentReader($ledger);

        $this->assertSame('some-value', $reader->get('MY_VAR'));
    }

    public function testARecordedUnsetVariableReplaysAsFalseNotAnException(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Env, 'MY_VAR', ['name' => 'MY_VAR'], false),
        ]);
        $reader = new StubbedEnvironmentReader($ledger);

        $this->assertFalse($reader->get('MY_VAR'));
    }

    public function testAVariableWithNoRecordedEffectThrows(): void
    {
        $reader = new StubbedEnvironmentReader(new EffectLedger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MY_VAR/');

        $reader->get('MY_VAR');
    }

    public function testTwoSequentialReadsOfTheSameVariableReplayInRecordedOrder(): void
    {
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Env, 'MY_VAR', ['name' => 'MY_VAR'], 'first'),
            new Effect(1, EffectKind::Env, 'MY_VAR', ['name' => 'MY_VAR'], 'second'),
        ]);
        $reader = new StubbedEnvironmentReader($ledger);

        $this->assertSame('first', $reader->get('MY_VAR'));
        $this->assertSame('second', $reader->get('MY_VAR'));
    }
}
