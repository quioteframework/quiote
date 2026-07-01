<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ViewNameResolver;
use Quiote\Util\Toolkit;
use Quiote\View\View;

/**
 * Comprehensive parity tests for ViewNameResolver vs legacy directive evaluation.
 */
class ViewNameResolverDirectiveParityTest extends UnitTestCase
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
            ['Default','Default', View::NONE],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testDirectiveParity(string $module, string $action, $rawViewName): void
    {
        if($rawViewName === View::NONE) {
            [$rm,$rn] = $this->resolver->resolve($module,$action,$rawViewName);
            $this->assertSame(View::NONE, $rn);
            $this->assertSame(View::NONE, $rm);
            return;
        }
        $evaluated = Toolkit::evaluateModuleDirective(
            $module,
            'quiote.view.name',
            ['actionName'=>$action,'viewName'=>$rawViewName]
        );
        $expected = $evaluated === '' || $evaluated === null ? $rawViewName : $evaluated;
        $expected = Toolkit::canonicalName($expected);
        [$resolvedModule,$resolvedName] = $this->resolver->resolve($module,$action,$rawViewName);
        $this->assertSame($module, $resolvedModule);
        $this->assertSame($expected, $resolvedName, sprintf('Mismatch for %s/%s raw=%s (expected %s, got %s)', $module,$action,$rawViewName,$expected,$resolvedName));
    }

    public function testArrayFormModuleOverride(): void
    {
        [$m,$v] = $this->resolver->resolve('Default','Default',['Cache','CustomSuccess']);
        $this->assertSame('Cache',$m);
        $this->assertSame(Toolkit::canonicalName('CustomSuccess'), $v);
    }
}
