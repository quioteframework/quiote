<?php

declare(strict_types=1);

namespace Quiote\Rector\Rector;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Quiote\Rector\NodeAnalyzer\ContextCallAnalyzer;

/**
 * `Context::getInstance('web')` to an injected `ContextRegistry`.
 *
 * ```php
 * // before
 * Context::getInstance('web')->getRouting()->gen(…);
 * // after
 * $this->contexts->get('web')->getRouting()->gen(…);
 * ```
 *
 * The one rule that rewrites a *static* reach rather than an instance call, which is also what
 * makes it the only one where the receiver cannot be type-resolved -- there is no receiver
 * expression, only a class name. So this matches on the name resolving to `Context` or a subclass,
 * which is exact rather than heuristic: a static call names its class outright.
 *
 * ## The target got simpler than the plan sketched
 *
 * The plan expected to emit
 * `$this->contexts->get('web')->getContainer()->get(Routing::class)->gen(…)` and noted that the
 * awkwardness was informative. It is no longer necessary: `ContextRegistry` exists, is bound in the
 * container as `ContextRegistry`/`contexts`, and `get()` answers a real `Context`, so the accessor
 * chain after it stays exactly as it was. Whatever the call site then reaches for is rule 1's, 2's,
 * 3's or 4's business on a later pass.
 *
 * The plan's other observation stands and is worth keeping: most of these sites probably want the
 * *current* context rather than a named lookup, and this rewrite makes that visible instead of
 * hiding it behind a static call. Expect the most human review per site of any rule here.
 *
 * A no-argument `Context::getInstance()` is rewritten to `get()` with no argument too, which the
 * registry reads as `core.default_context` -- the same meaning the static call had.
 *
 * @since      4.0.0
 */
final class ContextGetInstanceToRegistryRector extends AbstractContextInjectionRector
{
    private const string CONTEXT_REGISTRY = \Quiote\ContextRegistry::class;

    /**
     * @return     ?class-string
     * @since      4.0.0
     */
    protected function resolveInjectableForStaticCall(StaticCall $staticCall): ?string
    {
        if (!$staticCall->name instanceof Identifier
            || strcasecmp($staticCall->name->toString(), 'getInstance') !== 0) {
            return null;
        }

        if (!$staticCall->class instanceof Name) {
            // A dynamic class expression -- $class::getInstance() -- names nothing resolvable.
            return null;
        }

        $className = $staticCall->class->toString();
        if (in_array($className, ['self', 'static', 'parent'], true)) {
            // Inside Context itself or a subclass. Those are the framework's own bootstrap paths and
            // must keep reaching the registry directly, not through an injected copy of it.
            return null;
        }

        if (!class_exists($className) || !is_a($className, \Quiote\Context::class, true)) {
            // getInstance() is a common static name. Anything that is not a Quiote Context keeps it.
            return null;
        }

        return count($staticCall->getArgs()) <= 1 ? self::CONTEXT_REGISTRY : null;
    }

    protected function buildReplacementForStaticCall(StaticCall $staticCall, string $propertyName): Expr
    {
        return new MethodCall(
            new PropertyFetch(new Variable('this'), $propertyName),
            'get',
            $staticCall->getArgs(),
        );
    }

    /**
     * This rule has no instance-call form; everything it rewrites is static.
     *
     * @return     ?class-string
     * @since      4.0.0
     */
    protected function resolveInjectable(MethodCall $methodCall): ?string
    {
        return null;
    }
}
