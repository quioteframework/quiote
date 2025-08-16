<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ViewNameResolver;
use Agavi\View\AgaviView;

class ViewNameResolverTest extends AgaviUnitTestCase
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
        [$vm,$vn] = $resolver->resolve('Cache','Cache',AgaviView::NONE);
        $this->assertNull($vn);
        $this->assertNull($vm);
    }
}
