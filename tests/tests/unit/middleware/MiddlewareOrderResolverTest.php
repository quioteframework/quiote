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

    // --- guarded middleware: constraints are guarantees, not preferences ---

    public function testUnresolvedReferenceOnGuardedMiddlewareThrows(): void
    {
        // A framework middleware's before/after edge is the only thing fixing its
        // position; dropping it silently would leave e.g. "CSRF validation runs
        // before dispatch" true only by accident of the current priorities.
        $this->expectException(MiddlewareOrderException::class);
        $this->expectExceptionMessageMatches('/cannot be dropped/');

        $resolver = new MiddlewareOrderResolver(['Guarded\\Security']);
        $resolver->resolve([
            self::def('Guarded\\Security', 'before_action', before: 'DoesNotExist'),
        ]);
    }

    public function testAmbiguousReferenceOnGuardedMiddlewareThrows(): void
    {
        $this->expectException(MiddlewareOrderException::class);

        $resolver = new MiddlewareOrderResolver(['Guarded\\Security']);
        $resolver->resolve([
            self::def('Guarded\\Security', 'before_action', after: 'Duplicate'),
            self::def('One\\Duplicate', 'pre'),
            self::def('Two\\Duplicate', 'pre'),
        ]);
    }

    public function testUnguardedMiddlewareStillDegradesToADiagnostic(): void
    {
        // Anchoring to an optional package's middleware is legitimate, and that
        // package may not be installed. Such a middleware falls back to its own
        // phase/priority rather than failing the whole pipeline build.
        $resolver = new MiddlewareOrderResolver(['Guarded\\Security']);
        $ordered = $resolver->resolve([
            self::def('App\\Optional', 'pre', after: 'MaybeNotInstalled'),
        ]);

        $this->assertCount(1, $ordered);
        $this->assertCount(1, $resolver->getDiagnostics());
    }

    public function testGuardedMiddlewareWithAResolvableReferenceIsFine(): void
    {
        $resolver = new MiddlewareOrderResolver(['Guarded\\Security']);
        $ordered = $resolver->resolve([
            self::def('Guarded\\Security', 'before_action', after: 'Routing'),
            self::def('Core\\Routing', 'routing'),
        ]);

        $this->assertSame(
            ['Core\\Routing', 'Guarded\\Security'],
            array_map(static fn($d) => $d->fqcn, $ordered),
        );
        $this->assertSame([], $resolver->getDiagnostics());
    }

    public function testTheRealFrameworkGuardedSetIsUsedByDefault(): void
    {
        // The default must be the safe one: leniency has to be asked for explicitly,
        // or a caller that forgets the argument silently loses the guarantee.
        $this->expectException(MiddlewareOrderException::class);

        $resolver = new MiddlewareOrderResolver();
        $resolver->resolve([
            self::def(\Quiote\Middleware\SecurityMiddleware::class, 'before_action', after: 'NotScanned'),
        ]);
    }
}
