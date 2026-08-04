<?php

declare(strict_types=1);

namespace Quiote\Rector\Rector;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use Quiote\Rector\NodeAnalyzer\ContextCallAnalyzer;

/**
 * `$this->getContext()->getModel(…)` to an injected `ModelLocator`.
 *
 * ```php
 * // before
 * $order = $this->getContext()->getModel('Order', 'Sales');
 * // after
 * $order = $this->modelLocator->get('Order', 'Sales');
 * ```
 *
 * The plan called this "inject a locator", which then had to be designed. It exists now:
 * `Quiote\Model\ModelLocator`, bound as `ModelLocator`/`modelLocator`, and
 * `Context::getModel()` is already a thin delegation to it. So the arguments pass through
 * untouched -- `get()` takes the same `(modelName, moduleName, parameters)` triple, in the
 * same order, with the same defaults.
 *
 * Safe to inject into any of the four container-built hierarchies, singletons included. The
 * locator holds a shared-model cache, which *is* request-scoped state -- but the locator
 * clears it itself at the request boundary, so holding the locator does not hold the models.
 * That is the same reasoning that makes `RequestState` and `CurrentUser` injectable: the
 * accessor is stable even though what it answers is not.
 *
 * @since      4.0.0
 */
final class ContextGetModelToLocatorRector extends AbstractContextInjectionRector
{
    private const string MODEL_LOCATOR = \Quiote\Model\ModelLocator::class;

    /**
     * @return     ?class-string
     * @since      4.0.0
     */
    protected function resolveInjectable(MethodCall $methodCall): ?string
    {
        if (!$this->contextCallAnalyzer->isContextCall($methodCall, 'getModel')) {
            return null;
        }

        // getModel() and get() both take one to three arguments. Anything else is not the
        // accessor this rule knows about, whatever it is named.
        $argCount = count($methodCall->getArgs());

        return $argCount >= 1 && $argCount <= 3 ? self::MODEL_LOCATOR : null;
    }

    protected function buildReplacement(MethodCall $methodCall, string $propertyName): Expr
    {
        return new MethodCall(
            new PropertyFetch(new Variable('this'), $propertyName),
            'get',
            $methodCall->getArgs(),
        );
    }
}
