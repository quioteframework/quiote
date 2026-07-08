<?php

namespace Quiote\Execution;

/**
 * Minimal contract for the legacy-style output type proxy returned by
 * ImmutableViewInitContext::getOutputType(). Exists so callers relying on
 * $view->getOutputType()->getName() get a real typed contract instead of a
 * bare `object`.
 */
interface OutputTypeNameProvider
{
    public function getName(): string;
}
