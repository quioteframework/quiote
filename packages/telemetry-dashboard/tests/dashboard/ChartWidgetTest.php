<?php

use PHPUnit\Framework\TestCase;
use Quiote\Telemetry\Dashboard\ChartWidget;
use Symfony\Component\Tui\Render\RenderContext;

class ChartWidgetTest extends TestCase
{
    public function testExpandsVerticallyByDefault(): void
    {
        $this->assertTrue((new ChartWidget([]))->isVerticallyExpanded());
    }

    public function testExpandVerticallyCanBeDisabled(): void
    {
        $widget = new ChartWidget([]);
        $widget->expandVertically(false);

        $this->assertFalse($widget->isVerticallyExpanded());
    }

    public function testRendersExactlyTheAssignedNumberOfRows(): void
    {
        $widget = new ChartWidget([1.0, 2.0, 3.0]);

        $lines = $widget->render(new RenderContext(10, 4));

        $this->assertCount(4, $lines);
    }

    public function testResamplesToFitTheAssignedColumnWidth(): void
    {
        $widget = new ChartWidget(array_fill(0, 100, 1.0));

        $lines = $widget->render(new RenderContext(10, 1));

        $this->assertSame(10, mb_strlen($lines[0]));
    }

    public function testEmptySeriesRendersBlankRowsRatherThanCrashing(): void
    {
        $widget = new ChartWidget([]);

        $lines = $widget->render(new RenderContext(5, 3));

        $this->assertSame(['', '', ''], $lines);
    }

    public function testSetValuesReplacesTheSeriesUsedByFutureRenders(): void
    {
        $widget = new ChartWidget([0.0]);
        $widget->setValues([10.0]);

        $lines = $widget->render(new RenderContext(1, 1));

        $this->assertSame('█', $lines[0]);
    }

    public function testZeroOrNegativeDimensionsAreClampedToAtLeastOne(): void
    {
        $widget = new ChartWidget([1.0]);

        $lines = $widget->render(new RenderContext(0, 0));

        $this->assertCount(1, $lines);
        $this->assertSame(1, mb_strlen($lines[0]));
    }
}
