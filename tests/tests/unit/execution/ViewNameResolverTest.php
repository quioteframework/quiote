<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ViewNameResolver;
use Quiote\View\View;

class ViewNameResolverTest extends UnitTestCase
{
    public function testResolvesScalarViewName(): void
    {
        $resolver = new ViewNameResolver();
    [$vm,$vn] = $resolver->resolve('Cache','Cache','Success');
    $this->assertSame('Cache',$vm);
    $this->assertContains($vn, ['Success','CacheSuccess']);
    }

    public function testResolvesArrayViewName(): void
    {
        $resolver = new ViewNameResolver();
        [$vm,$vn] = $resolver->resolve('Cache','Cache',['Other','Alt']);
        $this->assertSame('Other',$vm);
        $this->assertSame('Alt',$vn);
    }

    public function testNoneConstant(): void
    {
        $resolver = new ViewNameResolver();
        [$vm,$vn] = $resolver->resolve('Cache','Cache',View::NONE);
        $this->assertNull($vn);
        $this->assertNull($vm);
    }

    public function testRejectsNonStringViewModuleFromArrayForm(): void
    {
        $resolver = new ViewNameResolver();
        $this->expectException(\UnexpectedValueException::class);
        $resolver->resolve('Cache', 'Cache', [123, 'Alt']);
    }

    public function testRejectsNonStringViewNameFromArrayForm(): void
    {
        $resolver = new ViewNameResolver();
        $this->expectException(\UnexpectedValueException::class);
        $resolver->resolve('Cache', 'Cache', ['Other', 123]);
    }
}
