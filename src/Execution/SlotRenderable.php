<?php
namespace Agavi\Execution;

/**
 * Marker interface for renderable slot results.
 * Future slot system will use this instead of AgaviExecutionContainer.
 */
interface SlotRenderable
{
    /** Return already-rendered slot content. */
    public function getContent(): string;
}
