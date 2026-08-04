<?php

declare(strict_types=1);

namespace Quiote\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;

/**
 * `$this->getContext()->getService(Foo::class)` to an injected `Foo`.
 *
 * The easiest of the rule set, and deliberately first: the service's class name is already an
 * argument to the call, so nothing has to map an identifier onto a class. That makes it the rule
 * that proves the shared machinery -- receiver type resolution, constructor injection, property
 * naming -- against the least additional risk.
 *
 * Only rewrites when the argument is a `::class` fetch. `getService($id)` with a variable, or with
 * a plain string, is left alone: the target would have to be guessed, and a guess here silently
 * injects the wrong collaborator.
 *
 * ```php
 * // before
 * final class TagAction extends Action
 * {
 *     public function executeRead(WebRequest $rd)
 *     {
 *         return $this->getContext()->getService(TagService::class)->tag($rd);
 *     }
 * }
 *
 * // after
 * final class TagAction extends Action
 * {
 *     public function __construct(private readonly TagService $tagService) {}
 *
 *     public function executeRead(WebRequest $rd)
 *     {
 *         return $this->tagService->tag($rd);
 *     }
 * }
 * ```
 *
 * No getRuleDefinition(): Rector 2.3's RectorInterface does not declare it, and the
 * symplify/rule-doc-generator value objects it returns are not shipped with the packaged build.
 * The example above serves the same purpose.
 *
 * @since      4.0.0
 */
final class ContextServiceToConstructorInjectionRector extends AbstractContextInjectionRector
{
    /**
     * The service class a `getService()` call names, or null when this is not such a call or the
     * argument is not a `::class` fetch.
     *
     * @return     ?class-string
     * @since      4.0.0
     */
    protected function resolveInjectable(MethodCall $methodCall): ?string
    {
        if (!$this->contextCallAnalyzer->isContextCall($methodCall, 'getService')) {
            return null;
        }

        $args = $methodCall->getArgs();
        if (count($args) !== 1) {
            return null;
        }

        $argument = $args[0]->value;
        if (!$argument instanceof ClassConstFetch || !$argument->class instanceof Node\Name) {
            // Not a ::class fetch. Guessing the target from a variable or a plain string would
            // inject the wrong collaborator silently, so this goes to the residue reporter.
            return null;
        }

        if (!$argument->name instanceof Identifier || strcasecmp($argument->name->toString(), 'class') !== 0) {
            return null;
        }

        $className = $argument->class->toString();

        if ($className === 'self' || $className === 'static' || $className === 'parent') {
            // Relative names would resolve against the file being rewritten, not the service.
            return null;
        }

        // A ::class fetch names something that may not exist -- a typo, or a class removed since the
        // call was written. Declining is right: injecting a type nothing can resolve turns a working
        // service lookup into a wiring failure.
        return class_exists($className) || interface_exists($className) ? $className : null;
    }

}
