<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Env\RecordingEnvironmentReader;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Environment\EnvironmentReaderInterface;

final class RecordingEnvironmentReaderTest extends TestCase
{
    private function readerReturning(string|false $value): EnvironmentReaderInterface
    {
        return new class($value) implements EnvironmentReaderInterface {
            public function __construct(private readonly string|false $value)
            {
            }

            public function get(string $name): string|false
            {
                return $this->value;
            }
        };
    }

    public function testASetVariableIsRecordedAndReturnedUntouched(): void
    {
        $ledger = new EffectLedger();
        $reader = new RecordingEnvironmentReader($this->readerReturning('some-value'), $ledger);

        $result = $reader->get('MY_VAR');

        $this->assertSame('some-value', $result);
        $effects = $ledger->all();
        $this->assertCount(1, $effects);
        $this->assertSame(EffectKind::Env, $effects[0]->kind);
        $this->assertSame('MY_VAR', $effects[0]->fingerprint);
        $this->assertSame('some-value', $effects[0]->result);
    }

    public function testAnUnsetVariableIsRecordedAsFalseDistinctFromAnEmptyString(): void
    {
        $ledger = new EffectLedger();
        $reader = new RecordingEnvironmentReader($this->readerReturning(false), $ledger);

        $result = $reader->get('MY_VAR');

        $this->assertFalse($result);
        $this->assertFalse($ledger->all()[0]->result);
    }

    public function testAnEmptyStringValueIsRecordedDistinctlyFromUnset(): void
    {
        $ledger = new EffectLedger();
        $reader = new RecordingEnvironmentReader($this->readerReturning(''), $ledger);

        $result = $reader->get('MY_VAR');

        $this->assertSame('', $result);
        $this->assertSame('', $ledger->all()[0]->result);
    }

    public function testTwoSequentialReadsOfTheSameVariableProduceTwoOrderedEffects(): void
    {
        $ledger = new EffectLedger();
        $reader = new RecordingEnvironmentReader($this->readerReturning('v1'), $ledger);

        $reader->get('MY_VAR');
        $reader->get('MY_VAR');

        $this->assertCount(2, $ledger->all());
    }

    public function testARealReaderExceptionPropagatesAndRecordsNothing(): void
    {
        $throwing = new class implements EnvironmentReaderInterface {
            public function get(string $name): string|false
            {
                throw new \RuntimeException('boom');
            }
        };
        $ledger = new EffectLedger();
        $reader = new RecordingEnvironmentReader($throwing, $ledger);

        try {
            $reader->get('MY_VAR');
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $ledger->all());
    }
}
