<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\ExceptionNotifier\ExceptionStatusMapper;

final class ExceptionStatusMapperTest extends TestCase
{
    public function testInvalidArgumentExceptionMapsTo400(): void
    {
        $this->assertSame(400, ExceptionStatusMapper::map(new InvalidArgumentException('bad input')));
    }

    public function testDomainExceptionMapsTo422(): void
    {
        $this->assertSame(422, ExceptionStatusMapper::map(new DomainException('unprocessable')));
    }

    public function testUnmappedExceptionMapsTo500(): void
    {
        $this->assertSame(500, ExceptionStatusMapper::map(new RuntimeException('boom')));
    }

    public function testASubclassOfAMappedExceptionInheritsItsStatus(): void
    {
        $exception = new class ('bad') extends InvalidArgumentException {
        };
        $this->assertSame(400, ExceptionStatusMapper::map($exception));
    }
}
