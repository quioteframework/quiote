<?php

use PHPUnit\Framework\TestCase;
use Quiote\Exception\QuioteException;
use Quiote\Routing\RoutingValue;

/**
 * Covers the ArrayAccess offset-type guards and __toString() scalar/Stringable
 * guard added to make RoutingValue safe under PHPStan level 9 (both were
 * previously assuming an untyped mixed offset/value without validation).
 */
class RoutingValueTest extends TestCase
{
    public function testOffsetExistsWithKnownStringOffsetReturnsTrue(): void
    {
        $value = new RoutingValue('foo');
        $this->assertTrue($value->offsetExists('val'));
    }

    public function testOffsetExistsWithNonStringOffsetReturnsFalse(): void
    {
        $value = new RoutingValue('foo');
        $this->assertFalse($value->offsetExists(0));
    }

    public function testOffsetGetWithNonStringOffsetReturnsNull(): void
    {
        $value = new RoutingValue('foo');
        $this->assertNull($value->offsetGet(0));
    }

    public function testOffsetSetWithNonStringOffsetIsIgnored(): void
    {
        $value = new RoutingValue('foo');
        $value->offsetSet(0, 'bar');
        $this->assertSame('foo', $value->getValue());
    }

    public function testOffsetSetWithKnownStringOffsetUpdatesValue(): void
    {
        $value = new RoutingValue('foo');
        $value->offsetSet('val', 'bar');
        $this->assertSame('bar', $value->getValue());
    }

    public function testOffsetUnsetWithNonStringOffsetIsIgnored(): void
    {
        $value = new RoutingValue('foo');
        $value->setPrefix('pre');
        $value->offsetUnset(0);
        $this->assertSame('pre', $value->getPrefix());
    }

    public function testToStringWithScalarValueEncodesIt(): void
    {
        $value = new RoutingValue('a b');
        $this->assertSame('a%20b', (string) $value);
    }

    public function testToStringWithStringableValueEncodesIt(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'a b';
            }
        };
        $value = new RoutingValue($stringable);
        $this->assertSame('a%20b', (string) $value);
    }

    public function testToStringWithNullValueReturnsEmptyString(): void
    {
        $value = new RoutingValue(null);
        $this->assertSame('', (string) $value);
    }

    public function testToStringWithNonScalarNonStringableValueThrows(): void
    {
        $value = new RoutingValue(['not', 'scalar']);
        $this->expectException(QuioteException::class);
        (string) $value;
    }
}
