<?php

declare(strict_types=1);

namespace Quiote\Asset;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped collection of page assets (stylesheets, scripts) accumulated
 * while rendering a page and its nested slots.
 *
 * This exists because a page's render tree is not one object: the top-level
 * View and every slot rendered via View::createSlotContent() get their own,
 * separate Action/View instances (see SlotDispatcher::dispatch()), each with
 * its own private attribute holder. Nothing local to any one View instance is
 * visible to the layout template that finally emits <link>/<script> tags. The
 * one thing every node in that tree shares is Context, so the registry lives
 * there (Context::getAssetRegistry()) rather than on WebRequest or any View.
 *
 * Reached from templates via the renderer "assigns" mechanism (see
 * Quiote\Renderer\Renderer), e.g. a renderer parameter
 * assigns.asset_registry = "assets" makes it available as $assets.
 *
 * Deduplicates at insertion time (an asset appended by two different slots
 * still renders once), preserving first-insertion order.
 */
final class AssetRegistry implements ResetInterface
{
    /** @var array<string, true> insertion-ordered set */
    private array $css = [];

    /** @var array<string, true> insertion-ordered set */
    private array $javascript = [];

    public function addCss(string $href): void
    {
        $this->css[$href] = true;
    }

    public function addJavascript(string $src): void
    {
        $this->javascript[$src] = true;
    }

    /**
     * @return list<string>
     */
    public function css(): array
    {
        return array_keys($this->css);
    }

    /**
     * @return list<string>
     */
    public function javascript(): array
    {
        return array_keys($this->javascript);
    }

    #[\Override]
    public function reset(): void
    {
        $this->css = [];
        $this->javascript = [];
    }
}
