<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Execution\ViewNameResolver;
use Agavi\Util\AgaviToolkit;
use Agavi\View\AgaviView;

/**
 * Comprehensive parity tests for ViewNameResolver vs legacy directive evaluation.
 */
class ViewNameResolverDirectiveParityTest extends AgaviUnitTestCase
{
    private ViewNameResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ViewNameResolver();
    }

    public static function cases(): array
    {
        return [
            ['Cache','CacheComplex','Success'],
            ['Cache','CacheComplex','Error'],
            ['Method','MethodHttp','PostError'],
            ['Default','Default','Success'],
            ['Default','Default', AgaviView::NONE],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testDirectiveParity(string $module, string $action, $rawViewName): void
    {
        if($rawViewName === AgaviView::NONE) {
            [$rm,$rn] = $this->resolver->resolve($module,$action,$rawViewName);
            $this->assertSame(AgaviView::NONE, $rn);
            $this->assertSame(AgaviView::NONE, $rm);
            return;
        }
        $evaluated = AgaviToolkit::evaluateModuleDirective(
            $module,
            'agavi.view.name',
            ['actionName'=>$action,'viewName'=>$rawViewName]
        );
        $expected = $evaluated === '' || $evaluated === null ? $rawViewName : $evaluated;
        $expected = AgaviToolkit::canonicalName($expected);
        [$resolvedModule,$resolvedName] = $this->resolver->resolve($module,$action,$rawViewName);
        $this->assertSame($module, $resolvedModule);
        $this->assertSame($expected, $resolvedName, sprintf('Mismatch for %s/%s raw=%s (expected %s, got %s)', $module,$action,$rawViewName,$expected,$resolvedName));
    }

    public function testArrayFormModuleOverride(): void
    {
        [$m,$v] = $this->resolver->resolve('Default','Default',['Cache','CustomSuccess']);
        $this->assertSame('Cache',$m);
        $this->assertSame(AgaviToolkit::canonicalName('CustomSuccess'), $v);
    }
}
