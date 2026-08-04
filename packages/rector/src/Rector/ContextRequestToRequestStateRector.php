<?php

declare(strict_types=1);

namespace Quiote\Rector\Rector;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use Quiote\Rector\NodeAnalyzer\ContextCallAnalyzer;

/**
 * The request half of the plan's rule 3, targeting `RequestState` instead of the `$rd` parameter.
 *
 * ```php
 * // before
 * $id = $this->getContext()->getRequest()->getParameter('id');
 * $this->getContext()->setRequest($replacement);
 * // after
 * $id = $this->requestState->current()->getParameter('id');
 * $this->requestState->publish($replacement);
 * ```
 *
 * ## Why not the `$rd` parameter the method already has
 *
 * That was the plan's approach, and it is a validation bypass. A parameter holds the request as it
 * was at method *entry*; `WebRequest` is immutable, so anything that mutates it publishes a
 * replacement, and from that point the parameter is stale while the context is authoritative.
 * `Quiote\Execution\ValidationService` re-reads from the context precisely because
 * `pruneParametersToValidated()` has republished, and its `validate*()` must see the post-prune
 * request. Substituting the parameter there feeds it un-pruned parameters.
 *
 * `RequestState::current()` resolves per call, so it preserves `Context::getRequest()`'s semantics
 * exactly. It is also the only form that is safe in every hierarchy: it holds nothing, so a
 * singleton-scoped `Service` may inject it where injecting a `WebRequest` would be refused.
 *
 * ## The carve-out
 *
 * A chain whose result is discarded is never rewritten:
 *
 * ```php
 * $this->getContext()->getRequest()->setAttribute('populate', [...]);   // left alone
 * ```
 *
 * Every `WebRequest` mutator returns a new instance, so that statement already does nothing.
 * Rewriting it to `$this->requestState->current()->setAttribute(...)` would be identically broken
 * but look deliberate, in freshly reviewed code. Those sites need `FormPopulationConfig` and a
 * `publish()` -- a change of meaning, so they belong to a human and to the residue reporter.
 *
 * @since      4.0.0
 */
final class ContextRequestToRequestStateRector extends AbstractContextInjectionRector
{
    private const string REQUEST_STATE = \Quiote\Request\RequestState::class;

    /**
     * @var        array<int, MethodCall> Calls in this class whose result is thrown away.
     */
    private array $discarded = [];

    protected function prepare(Class_ $class): void
    {
        $this->discarded = [];

        $this->traverseNodesWithCallable($class->stmts, function ($node): null {
            if (!$node instanceof Expression || !$node->expr instanceof MethodCall) {
                return null;
            }

            // Walk to the root of the chain: in $x->getRequest()->setAttribute(...) the mutator is
            // outermost, so the accessor is reached by descending through the receivers.
            $receiver = $node->expr->var;
            while ($receiver instanceof MethodCall) {
                if ($this->contextCallAnalyzer->isContextCall($receiver, 'getRequest')) {
                    $this->discarded[] = $receiver;
                }
                $receiver = $receiver->var;
            }

            return null;
        });
    }

    /**
     * @return     ?class-string
     * @since      4.0.0
     */
    protected function resolveInjectable(MethodCall $methodCall): ?string
    {
        if ($this->contextCallAnalyzer->isContextCall($methodCall, 'getRequest')) {
            if ($methodCall->getArgs() !== []) {
                return null;
            }

            foreach ($this->discarded as $discardedCall) {
                if ($discardedCall === $methodCall) {
                    return null;
                }
            }

            return self::REQUEST_STATE;
        }

        if ($this->contextCallAnalyzer->isContextCall($methodCall, 'setRequest')) {
            // publish() takes exactly the one request setRequest() does.
            return count($methodCall->getArgs()) === 1 ? self::REQUEST_STATE : null;
        }

        return null;
    }

    protected function buildReplacement(MethodCall $methodCall, string $propertyName): Expr
    {
        $property = new PropertyFetch(new Variable('this'), $propertyName);

        if ($this->contextCallAnalyzer->isContextCall($methodCall, 'setRequest')) {
            return new MethodCall($property, 'publish', $methodCall->getArgs());
        }

        return new MethodCall($property, 'current');
    }
}
