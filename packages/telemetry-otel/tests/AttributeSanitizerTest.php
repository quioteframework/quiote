<?php

use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\AttributeSanitizer;

/**
 * The OTel SDK's own span/meter APIs require non-empty-string keys and
 * `array|bool|float|int|string|null` values (homogeneous arrays); this is
 * what turns an arbitrary `array<string, mixed>` from a call site into that
 * shape, or rejects it.
 */
class AttributeSanitizerTest extends TestCase
{
    public function testSanitizePassesThroughValidScalarAndNullValues(): void
    {
        $result = AttributeSanitizer::sanitize([
            'string' => 'value',
            'int' => 1,
            'float' => 1.5,
            'bool' => true,
            'null' => null,
        ]);

        $this->assertSame([
            'string' => 'value',
            'int' => 1,
            'float' => 1.5,
            'bool' => true,
            'null' => null,
        ], $result);
    }

    public function testSanitizePassesThroughAHomogeneousArrayValue(): void
    {
        $result = AttributeSanitizer::sanitize(['ids' => [1, 2, 3]]);

        $this->assertSame(['ids' => [1, 2, 3]], $result);
    }

    public function testSanitizeCastsIntegerKeysToStrings(): void
    {
        $result = AttributeSanitizer::sanitize([5 => 'five']);

        $this->assertSame(['5' => 'five'], $result);
    }

    public function testSanitizeThrowsOnAnEmptyStringKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty strings');

        AttributeSanitizer::sanitize(['' => 'value']);
    }

    public function testSanitizeThrowsOnAnUnsupportedScalarValueType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported value type "stdClass"');

        AttributeSanitizer::sanitize(['bad' => new stdClass()]);
    }

    public function testSanitizeThrowsOnAResourceValue(): void
    {
        $resource = fopen('php://memory', 'r');
        try {
            $this->expectException(\InvalidArgumentException::class);
            AttributeSanitizer::sanitize(['bad' => $resource]);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testSanitizeThrowsOnAnArrayContainingAnUnsupportedElementType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported element type');

        AttributeSanitizer::sanitize(['bad' => [1, new stdClass()]]);
    }

    public function testSanitizeThrowsOnAHeterogeneousArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be homogeneous');

        AttributeSanitizer::sanitize(['mixed' => [1, 'two']]);
    }

    public function testSanitizeEntryReturnsTheValidatedKeyAndValue(): void
    {
        [$key, $value] = AttributeSanitizer::sanitizeEntry('route', '/orders/{id}');

        $this->assertSame('route', $key);
        $this->assertSame('/orders/{id}', $value);
    }

    public function testSanitizeEntryStopsAtTheFirstOffendingEntryOnAMultiKeyMap(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AttributeSanitizer::sanitize([
            'ok' => 'fine',
            'bad' => new stdClass(),
            'never_reached' => 'value',
        ]);
    }
}
