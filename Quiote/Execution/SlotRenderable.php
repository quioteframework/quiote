<?php
namespace Quiote\Execution;

/**
 * Marker interface for renderable slot results.
 * Future slot system will use this instead of ExecutionContainer.
 */
interface SlotRenderable
{
    /** Return already-rendered slot content. */
    public function getContent(): string;
}
