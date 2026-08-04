<?php

declare(strict_types=1);

namespace Quiote\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Type\ObjectType;
use Quiote\Rector\NodeAnalyzer\ContextCallAnalyzer;
use Rector\Rector\AbstractRector;

/**
 * `$this->getContext()->getRequest()` to the `WebRequest` parameter the method already has.
 *
 * **NOT REGISTERED. Do not enable this rule as it stands -- it is unsound.**
 *
 * The premise it was written from -- "the method already has the current request as a parameter, so
 * substitute it" -- is false. A parameter holds the request as it was at method *entry*. Because
 * `WebRequest` is immutable, anything that mutates it publishes a replacement, and from that point
 * the parameter is stale while `Context::getRequest()` is authoritative.
 *
 * Running this against the framework found exactly one site, and it was a false positive of the worst
 * kind. `Quiote\Execution\ValidationService` re-reads the request from the context with a comment
 * explaining why: `pruneParametersToValidated()` has republished it, and the action's `validate*()`
 * method must see the *post-prune* request. Substituting the parameter there would have fed it the
 * un-pruned one -- a strict-validation bypass, introduced by a migration tool, in code that had just
 * been reviewed.
 *
 * Whether a republish happened between entry and the call site is not decidable locally: any nested
 * call may have done it. So the correct target is `RequestState::current()`, which resolves per call
 * and therefore preserves the "read the request as of now" semantics that
 * `Context::getRequest()` has. Rewriting to the parameter is only safe where nothing can have
 * republished, which a rule cannot establish.
 *
 * Kept, unregistered, because the two analyses in it are still right and reusable: the discarded-
 * mutation carve-out, and matching the parameter on its declared type rather than its name.
 *
 * The plan's rule 3 was to be split into a request half and a user half; this was the request half.
 *
 * ```php
 * // before
 * public function executeRead(WebRequest $rd)
 * {
 *     return $this->getContext()->getRequest()->getParameter('id');
 * }
 * // after
 * public function executeRead(WebRequest $rd)
 * {
 *     return $rd->getParameter('id');
 * }
 * ```
 *
 * ## Never an injected property either
 *
 * The same immutability that makes the parameter go stale rules out a constructor-injected request: a
 * property assigned at construction holds the *pre-validation* one. Both ends of this are wrong for
 * the same reason, which is why the answer is per-call resolution.
 *
 * ## The carve-out that matters
 *
 * A chain whose result is **discarded** is never rewritten:
 *
 * ```php
 * $this->getContext()->getRequest()->setAttribute('populate', [...]);   // left alone
 * ```
 *
 * Every `WebRequest` mutator returns a new instance; the call above therefore does nothing, and has
 * done nothing since the request became immutable. Rewriting it to `$rd->setAttribute(...)` would
 * produce an identically broken statement -- but one that now looks deliberate, in freshly reviewed
 * code. The plan counted 46 such sites in the reference application. They need
 * `FormPopulationConfig` and a `publish()`, which is a change of meaning, so they belong to a human
 * and to the residue reporter.
 *
 * @since      4.0.0
 */
final class ContextRequestToParameterRector extends AbstractRector
{
    public function __construct(private readonly ContextCallAnalyzer $contextCallAnalyzer) {}

    /**
     * @return     array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof ClassMethod || $node->stmts === null) {
            return null;
        }

        $requestParameter = $this->requestParameterName($node);
        if ($requestParameter === null) {
            // No WebRequest parameter to substitute. RequestState::current() is the target for these,
            // handled separately -- guessing a variable name here would produce code that does not
            // compile.
            return null;
        }

        $discarded = $this->discardedMutationCalls($node);
        $changed = false;

        $this->traverseNodesWithCallable(
            $node->stmts,
            function (Node $subNode) use ($requestParameter, $discarded, &$changed): ?Node {
                if (!$subNode instanceof MethodCall) {
                    return null;
                }

                if (!$this->contextCallAnalyzer->isContextCall($subNode, 'getRequest')) {
                    return null;
                }

                if ($subNode->getArgs() !== []) {
                    return null;
                }

                foreach ($discarded as $discardedCall) {
                    if ($discardedCall === $subNode) {
                        // A mutation whose result is thrown away. See the class docblock.
                        return null;
                    }
                }

                $changed = true;

                return new Variable($requestParameter);
            },
        );

        return $changed ? $node : null;
    }

    /**
     * The name of the method's `WebRequest` parameter, or null when it has none.
     *
     * Matched on the declared type rather than the name, so `$rd`, `$request` and an application's
     * own `WebRequest` subclass are all found, and a parameter merely *called* `$rd` with some other
     * type is not.
     *
     * @since      4.0.0
     */
    private function requestParameterName(ClassMethod $classMethod): ?string
    {
        $webRequestType = new ObjectType('Quiote\\Request\\WebRequest');

        foreach ($classMethod->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            if ($param->type === null) {
                continue;
            }

            $declared = $this->getType($param);
            if ($webRequestType->isSuperTypeOf($declared)->yes()) {
                return $param->var->name;
            }
        }

        return null;
    }

    /**
     * The `getRequest()` calls in this method whose surrounding chain is a discarded statement.
     *
     * A statement-level expression throws its value away. When that expression is a chain rooted in
     * `getRequest()`, the mutation it performs is already lost -- and rewriting it would hide that
     * rather than fix it.
     *
     * @return     array<int, MethodCall>
     * @since      4.0.0
     */
    private function discardedMutationCalls(ClassMethod $classMethod): array
    {
        $discarded = [];

        $this->traverseNodesWithCallable(
            (array) $classMethod->stmts,
            function (Node $subNode) use (&$discarded): null {
                if (!$subNode instanceof Expression || !$subNode->expr instanceof MethodCall) {
                    return null;
                }

                // Walk to the root of the chain: $x->getRequest()->setAttribute(...) has the
                // setAttribute call outermost.
                $receiver = $subNode->expr->var;
                while ($receiver instanceof MethodCall) {
                    if ($this->contextCallAnalyzer->isContextCall($receiver, 'getRequest')) {
                        $discarded[] = $receiver;
                    }
                    $receiver = $receiver->var;
                }

                return null;
            },
        );

        return $discarded;
    }
}
