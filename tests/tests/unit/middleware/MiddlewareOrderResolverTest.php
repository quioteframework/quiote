<?php

use PHPUnit\Framework\TestCase;
use Quiote\Middleware\Compiler\MiddlewareDefinition;
use Quiote\Middleware\Compiler\MiddlewareOrderResolver;
use Quiote\Middleware\Compiler\MiddlewareOrderException;
use Quiote\Support\Compiler\Diagnostic;

class MiddlewareOrderResolverTest extends TestCase
{
    private static function def(
        string $fqcn,
        string $phase,
        int $priority = 0,
        ?string $before = null,
        ?string $after = null,
        bool $enabled = true
    ): MiddlewareDefinition {
        return new MiddlewareDefinition($fqcn, $phase, $priority, $before, $after, $enabled, $fqcn);
    }

    public function testPhaseIsThePrimarySortKey(): void
    {
        $resolver = new MiddlewareOrderResolver();
        $ordered = $resolver->resolve([
            self::def('Finalize\\A', 'finalize'),
            self::def('Bootstrap\\A', 'bootstrap'),
            self::def('Routing\\A', 'routing'),
        ]);

        $this->assertSame(
            ['Bootstrap\\A', 'Routing\\A', 'Finalize\\A'],
            array_map(fn($d) => $d->fqcn, $ordered)
        );
    }

    public function testHigherPriorityRunsFirstWithinAPhase(): void
    {
        $resolver = new MiddlewareOrderResolver();
        $ordered = $resolver->resolve([
            self::def('Low', 'pre', priority: 1),
            self::def('High', 'pre', priority: 10),
        ]);

        $this->assertSame(['High', 'Low'], array_map(fn($d) => $d->fqcn, $ordered));
    }

    public function testBeforeAfterResolvesByShortClassName(): void
    {
        $resolver = new MiddlewareOrderResolver();
        $ordered = $resolver->resolve([
            self::def('App\\First', 'pre'),
            self::def('App\\Second', 'pre', before: 'First'),
        ]);

        $this->assertSame(['App\\Second', 'App\\First'], array_map(fn($d) => $d->fqcn, $ordered));
    }

    public function testBeforeAfterResolvesByFqcn(): void
    {
        $resolver = new MiddlewareOrderResolver();
        $ordered = $resolver->resolve([
            self::def('App\\First', 'pre'),
            self::def('App\\Second', 'pre', after: 'App\\First'),
        ]);

        $this->assertSame(['App\\First', 'App\\Second'], array_map(fn($d) => $d->fqcn, $ordered));
    }

    public function testAmbiguousShortNameIsSkippedWithDiagnostic(): void
    {
        $resolver = new MiddlewareOrderResolver();
        $ordered = $resolver->resolve([
            self::def('App\\Target', 'pre'),
            self::def('Other\\Target', 'pre'),
            self::def('App\\Dependent', 'pre', after: 'Target'),
        ]);

        // The ambiguous edge is dropped, not fatal — all three still appear.
        $this->assertCount(3, $ordered);
        $diagnostics = $resolver->getDiagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame(MiddlewareOrderResolver::CODE_AMBIGUOUS_REFERENCE, $diagnostics[0]->code);
    }

    public function testUnresolvedReferenceIsSkippedWithDiagnostic(): void
    {
        $resolver = new MiddlewareOrderResolver();
        $ordered = $resolver->resolve([
            self::def('App\\Dependent', 'pre', after: 'DoesNotExist'),
        ]);

        $this->assertCount(1, $ordered);
        $diagnostics = $resolver->getDiagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame(MiddlewareOrderResolver::CODE_UNRESOLVED_REFERENCE, $diagnostics[0]->code);
    }

    public function testCycleThrows(): void
    {
        $this->expectException(MiddlewareOrderException::class);

        $resolver = new MiddlewareOrderResolver();
        $resolver->resolve([
            self::def('App\\A', 'pre', before: 'B'),
            self::def('App\\B', 'pre', before: 'A'),
        ]);
    }
}
