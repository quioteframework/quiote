<?php
namespace Quiote\Execution;

/**
 * Marker interface for renderable slot results.
 */
interface SlotRenderable
{
    /** Return already-rendered slot content. */
    public function getContent(): string;
}
