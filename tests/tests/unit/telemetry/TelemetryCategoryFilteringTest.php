<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Telemetry\NoopSpanHandle;
use Quiote\Telemetry\TelemetryBootstrap;
use Quiote\Telemetry\Trace;
use Quiote\Telemetry\TraceRegistry;

/**
 * Phase 5 tests for category-based trace filtering: the cascade semantics
 * (deliberately NOT longest-prefix-wins like LogRegistry), the default
 * fallback, and — the acceptance criteria from docs/OPENTELEMETRY_PLAN.md —
 * that a span whose category is filtered out still lets a later, enabled
 * span correctly parent onto the nearest recorded ancestor.
 */
class TelemetryCategoryFilteringTest extends TestCase
{
    #[Before]
    public function setUpTelemetry(): void
    {
        TelemetryBootstrap::reset();
    }

    #[After]
    public function tearDownTelemetry(): void
    {
        TelemetryBootstrap::reset();
        Config::remove('telemetry.enabled');
        Config::remove('telemetry.exporter');
        Config::remove('telemetry.export.mode');
        Config::remove('telemetry.sampling.strategy');
    }

    private function enable(): void
    {
        Config::set('telemetry.enabled', true, true);
        Config::set('telemetry.exporter', 'none', true);
        Config::set('telemetry.export.mode', 'simple', true);
        Config::set('telemetry.sampling.strategy', 'always_on', true); // isolate category filtering from Phase 4 sampling
        TelemetryBootstrap::configureFromConfig();
    }

    private function exportedSpanNames(): array
    {
        return array_map(static fn($s) => $s->getName(), TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    // --- basic on/off -----------------------------------------------------------

    public function testCategoryEnabledByDefault(): void
    {
        $this->enable();
        Trace::span('Quiote.Routing', 'match')->end();
        $this->assertContains('match', $this->exportedSpanNames());
    }

    public function testDisabledCategoryProducesNoopHandle(): void
    {
        $this->enable();
        Trace::setCategoryEnabled('Quiote.Routing', false);

        $span = Trace::span('Quiote.Routing', 'match');
        $this->assertInstanceOf(NoopSpanHandle::class, $span);
        $span->end();

        $this->assertCount(0, TelemetryBootstrap::inMemorySpanExporter()->getSpans());
    }

    public function testExactCategoryMatchIsDisabled(): void
    {
        $this->enable();
        Trace::setCategoryEnabled('Quiote.Routing', false);

        Trace::span('Quiote.Routing', 'exact')->end();
        $this->assertNotContains('exact', $this->exportedSpanNames());
    }

    public function testUnrelatedTopLevelSegmentIsNotAffected(): void
    {
        // "Quiote" prefix must not match a different top-level segment
        // (same guard LoggingTest asserts for LogRegistry::resolveLevel()).
        $this->enable();
        Trace::setCategoryEnabled('Quiote', false);

        Trace::span('Extras.Thing', 'x')->end();
        $this->assertContains('x', $this->exportedSpanNames());
    }

    // --- the cascade: the deliberate divergence from LogRegistry ---------------

    public function testDisablingAnAncestorDisablesTheWholeSubtree(): void
    {
        $this->enable();
        Trace::setCategoryEnabled('Quiote.Validation', false);

        Trace::span('Quiote.Validation.Rules', 'a')->end();
        Trace::span('Quiote.Validation.Rules.Custom', 'b')->end();

        $names = $this->exportedSpanNames();
        $this->assertNotContains('a', $names);
        $this->assertNotContains('b', $names);
    }

    public function testExplicitTrueOnADescendantCannotOverrideADisabledAncestor(): void
    {
        // The core divergence from LogRegistry's longest-prefix-wins: logging
        // would let this more specific "true" win; trace category filtering
        // must not.
        $this->enable();
        Trace::setCategories([
            'Quiote.Validation' => false,
            'Quiote.Validation.Rules' => true,
        ]);

        Trace::span('Quiote.Validation.Rules', 'a')->end();

        $this->assertNotContains('a', $this->exportedSpanNames());
    }

    public function testDisablingAChildLeavesSiblingsAndParentUnaffected(): void
    {
        $this->enable();
        Trace::setCategoryEnabled('Quiote.Validation.Rules', false);

        Trace::span('Quiote.Validation', 'parent-level')->end();
        Trace::span('Quiote.Validation.Other', 'sibling')->end();
        Trace::span('Quiote.Validation.Rules', 'disabled-child')->end();

        $names = $this->exportedSpanNames();
        $this->assertContains('parent-level', $names);
        $this->assertContains('sibling', $names);
        $this->assertNotContains('disabled-child', $names);
    }

    public function testLongestPrefixWinsAmongMultipleTrueEntriesWhenNothingIsDisabled(): void
    {
        // With nothing disabled on the chain, resolution among explicit
        // `true` entries still follows longest-prefix — same mechanics as
        // LogRegistry, just for the positive case.
        $this->enable();
        Trace::setCategories([
            'App' => true,
            'App.Orders' => true,
        ]);

        Trace::span('App.Orders.Checkout', 'x')->end();
        $this->assertContains('x', $this->exportedSpanNames());
    }

    // --- default fallback ---------------------------------------------------------

    public function testDefaultCategoryEnabledIsTrueByDefault(): void
    {
        $this->enable();
        Trace::span('Totally.Unconfigured.Category', 'x')->end();
        $this->assertContains('x', $this->exportedSpanNames());
    }

    public function testSetDefaultCategoryEnabledFalseAppliesToUnmatchedCategories(): void
    {
        $this->enable();
        Trace::setDefaultCategoryEnabled(false);

        Trace::span('Totally.Unconfigured.Category', 'x')->end();
        $this->assertNotContains('x', $this->exportedSpanNames());
    }

    public function testExplicitTrueOverridesAFalseDefault(): void
    {
        $this->enable();
        Trace::setDefaultCategoryEnabled(false);
        Trace::setCategoryEnabled('App.Important', true);

        Trace::span('App.Important', 'x')->end();
        $this->assertContains('x', $this->exportedSpanNames());
    }

    // --- acceptance: non-recording propagation, not a broken tree --------------

    public function testSpanAfterAFilteredCallStillParentsOntoNearestRecordedAncestor(): void
    {
        $this->enable();
        Trace::setCategoryEnabled('Quiote.Validation', false);

        $root = Trace::span('Quiote.Http', 'root'); // real, activated — becomes "current"

        // Filtered out: no context is touched, so "current" is still $root.
        $skipped = Trace::span('Quiote.Validation', 'rules');
        $this->assertInstanceOf(NoopSpanHandle::class, $skipped);

        // A differently-categorized (enabled) span opened while logically
        // "underneath" the filtered call must still parent onto $root, not
        // onto nothing.
        $child = Trace::span('App.Custom', 'thing');
        $child->end();
        $skipped->end();
        $root->end();

        $spans = TelemetryBootstrap::inMemorySpanExporter()->getSpans();
        $byName = [];
        foreach ($spans as $s) {
            $byName[$s->getName()] = $s;
        }

        $this->assertArrayHasKey('root', $byName);
        $this->assertArrayHasKey('thing', $byName);
        $this->assertArrayNotHasKey('rules', $byName, 'the filtered-out span must never be exported');
        $this->assertSame(
            $byName['root']->getContext()->getSpanId(),
            $byName['thing']->getParentSpanId(),
            'a span opened after a filtered-out call must parent onto the nearest recorded ancestor'
        );
    }

    // --- reset / lifecycle -------------------------------------------------------

    public function testResetClearsCategoryConfiguration(): void
    {
        $this->enable();
        Trace::setCategoryEnabled('Quiote.Routing', false);
        TraceRegistry::reset();

        $this->assertTrue(TraceRegistry::isCategoryEnabled('Quiote.Routing'), 'reset() must clear category overrides back to the default-enabled state');
    }

    // --- metrics are category-agnostic ------------------------------------------

    public function testMetricsAreRecordedRegardlessOfCategoryFiltering(): void
    {
        // Category filtering applies to spans only (docs/OPENTELEMETRY_PLAN.md,
        // Phase 5) — Trace::metrics() takes no category argument at all, so
        // there's nothing to filter; this documents that invariant explicitly.
        $this->enable();
        Trace::setCategoryEnabled('Quiote.Http', false);
        Trace::setDefaultCategoryEnabled(false);

        Trace::metrics()->addCounter('http.server.request.count', 1);
        TelemetryBootstrap::flushAfterRequest();

        $names = array_map(static fn($m) => $m->name, TelemetryBootstrap::inMemoryMetricExporter()->collect());
        $this->assertContains('http.server.request.count', $names);
    }
}
