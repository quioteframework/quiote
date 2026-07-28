<?php

use Quiote\Openapi\RoutePathTemplate;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Symfony route path syntax -> OpenAPI path template, with the extra syntax
 * (inline requirements, inline defaults, the `!` important marker) lifted out
 * instead of dropped -- see RoutePathTemplate.
 */
final class RoutePathTemplateTest extends PhpUnitTestCase
{
    public function testStaticPathHasNoVariables(): void
    {
        $template = RoutePathTemplate::parse('/products');

        $this->assertSame('/products', $template->path);
        $this->assertSame([], $template->variables);
        $this->assertSame([], $template->requirements);
        $this->assertSame([], $template->defaults);
    }

    public function testPlainPlaceholdersArePassedThroughInOrder(): void
    {
        $template = RoutePathTemplate::parse('/orders/{orderId}/lines/{lineId}');

        $this->assertSame('/orders/{orderId}/lines/{lineId}', $template->path);
        $this->assertSame(['orderId', 'lineId'], $template->variables);
    }

    public function testInlineRequirementBecomesARequirementAndLeavesABarePlaceholder(): void
    {
        $template = RoutePathTemplate::parse('/orders/{id<\d+>}');

        $this->assertSame('/orders/{id}', $template->path);
        $this->assertSame(['id'], $template->variables);
        $this->assertSame(['id' => '\d+'], $template->requirements);
    }

    public function testInlineRequirementMayContainItsOwnBraces(): void
    {
        // The quantifier's '}' must not be mistaken for the placeholder's own.
        $template = RoutePathTemplate::parse('/codes/{code<[A-Z]{2,4}>}/detail');

        $this->assertSame('/codes/{code}/detail', $template->path);
        $this->assertSame(['code' => '[A-Z]{2,4}'], $template->requirements);
    }

    public function testInlineDefaultIsLiftedOut(): void
    {
        $template = RoutePathTemplate::parse('/list/{page?1}');

        $this->assertSame('/list/{page}', $template->path);
        $this->assertSame(['page' => '1'], $template->defaults);
    }

    public function testBarePlaceholderDefaultIsAnEmptyString(): void
    {
        $template = RoutePathTemplate::parse('/list/{page?}');

        $this->assertSame('/list/{page}', $template->path);
        $this->assertSame(['page' => ''], $template->defaults);
    }

    public function testImportantMarkerAndRequirementAndDefaultCombine(): void
    {
        $template = RoutePathTemplate::parse('/{!locale<[a-z]{2}>?en}/about');

        $this->assertSame('/{locale}/about', $template->path);
        $this->assertSame(['locale'], $template->variables);
        $this->assertSame(['locale' => '[a-z]{2}'], $template->requirements);
        $this->assertSame(['locale' => 'en'], $template->defaults);
    }

    public function testARepeatedPlaceholderIsListedOnce(): void
    {
        $template = RoutePathTemplate::parse('/{lang}/x/{lang}');

        $this->assertSame('/{lang}/x/{lang}', $template->path);
        $this->assertSame(['lang'], $template->variables);
    }

    public function testUnbalancedBraceLeavesTheRestOfThePathUntouched(): void
    {
        $template = RoutePathTemplate::parse('/broken/{id');

        $this->assertSame('/broken/{id', $template->path);
        $this->assertSame([], $template->variables);
    }

    public function testEmptyPlaceholderIsKeptVerbatim(): void
    {
        $template = RoutePathTemplate::parse('/weird/{}/tail');

        $this->assertSame('/weird/{}/tail', $template->path);
        $this->assertSame([], $template->variables);
    }
}
