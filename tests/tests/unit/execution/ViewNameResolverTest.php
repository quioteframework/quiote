<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ViewNameResolver;
use Quiote\View\View;

class ViewNameResolverTest extends UnitTestCase
{
    public function testResolvesScalarViewName()
    {
        $resolver = new ViewNameResolver();
    [$vm,$vn] = $resolver->resolve('Cache','Cache','Success');
    $this->assertSame('Cache',$vm);
    $this->assertContains($vn, ['Success','CacheSuccess']);
    }

    public function testResolvesArrayViewName()
    {
        $resolver = new ViewNameResolver();
        [$vm,$vn] = $resolver->resolve('Cache','Cache',['Other','Alt']);
        $this->assertSame('Other',$vm);
        $this->assertSame('Alt',$vn);
    }

    public function testNoneConstant()
    {
        $resolver = new ViewNameResolver();
        [$vm,$vn] = $resolver->resolve('Cache','Cache',View::NONE);
        $this->assertNull($vn);
        $this->assertNull($vm);
    }
}
