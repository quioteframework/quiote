<?php

namespace Quiote\Telemetry\Dashboard;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * A leaf widget that renders a numeric series as a multi-row bar chart,
 * filling whatever height and width it is assigned at render time. This is
 * what makes the dashboard's throughput/latency panels genuinely tall (not
 * the single glyph row {@see Spark}'s original design produced) and
 * responsive to terminal resizes: `render()` reads `$context->getRows()`/
 * `getColumns()` fresh on every frame and re-resamples/re-draws to fit.
 *
 * Implements `VerticallyExpandableInterface` directly on a leaf widget
 * (`symfony/tui` only ships this on `ContainerWidget`/`EditorWidget`, but the
 * interface itself has no such restriction) so a plain `ContainerWidget`
 * ancestor's `isVerticallyExpanded()` -- "true if explicitly set, or if any
 * child needs to expand" -- picks this widget up automatically and the
 * "give the chart whatever space is left over" behavior propagates up
 * through the widget tree with no manual `expandVertically()` calls needed
 * on any wrapping container.
 */
final class ChartWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $expand = true;

    /** @param float[] $values */
    public function __construct(private array $values = [])
    {
    }

    /** @param float[] $values @return $this */
    public function setValues(array $values): static
    {
        $this->values = $values;
        $this->invalidate();

        return $this;
    }

    /**
     * Sets whether the chart claims the leftover vertical space of its
     * container, invalidating the widget so the next frame re-lays it out.
     * Enabled by default.
     */
    public function expandVertically(bool $expand): static
    {
        $this->expand = $expand;
        $this->invalidate();

        return $this;
    }

    /**
     * Whether the chart currently claims leftover vertical space; ancestor
     * containers consult this to propagate expansion up the widget tree.
     */
    public function isVerticallyExpanded(): bool
    {
        return $this->expand;
    }

    /**
     * Draws the current values as a bar chart filling the assigned area.
     *
     * Reads the row and column counts from $context on every frame — each
     * floored at 1 — and resamples the series to that width before drawing,
     * so a terminal resize is picked up without any explicit reconfiguration.
     */
    public function render(RenderContext $context): array
    {
        $columns = max(1, $context->getColumns());
        $rows = max(1, $context->getRows());

        return Spark::renderBars(Spark::resample($this->values, $columns), $rows);
    }
}
