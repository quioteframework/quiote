<?php

declare(strict_types=1);

namespace Quiote\Rector\Rector;

use PhpParser\Node\Expr\MethodCall;

/**
 * Process-lifetime Context accessors to injected collaborators.
 *
 * ```php
 * // before
 * $this->getContext()->getRouting()->gen('order.show', ['id' => $id]);
 * // after
 * $this->routing->gen('order.show', ['id' => $id]);
 * ```
 *
 * These are safe to hold because they live for the process: they are built once at initialize() and
 * are not replaced at the request boundary the way the request and user are.
 *
 * ## The two optional components are included, and what makes that safe
 *
 * `getTranslationManager()` and `getDatabaseManager()` answered null in a context that configures
 * neither, so a call site guards with `?->`. What made injecting them unsafe was not the null: it was
 * that both classes are instantiable with zero required constructor arguments, so a container asked
 * for one it had no binding for would autowire a brand-new, uninitialized instance -- a translation
 * manager with no locales -- and the guard, rewritten to a property fetch, would sail past it.
 *
 * `Context` binds them either way now: to the component when the configuration declares one, and
 * otherwise to a factory that says which configuration would have. An injected dependency therefore
 * either is the real component or fails naming the cause, which is what makes the substitution a
 * substitution rather than a judgement.
 *
 * A `?->` at the call site survives the rewrite as `$this->translationManager?->…`, so nothing
 * changes meaning; the branch it guards has simply become unreachable, and collapsing it to `->` is a
 * tidy-up a reader can make later.
 *
 * `getDatabaseConnection()` is deliberately still absent: its replacement is a call on the injected
 * manager rather than the manager itself, which is a different rewrite and not a mapping entry.
 *
 * @since      4.0.0
 */
final class ContextAccessorToConstructorInjectionRector extends AbstractContextInjectionRector
{
    /**
     * Accessor name => the class to inject for it.
     *
     * @var        array<string, class-string>
     */
    private const array ACCESSOR_INJECTIONS = [
        'getRouting' => \Quiote\Routing\Routing::class,
        'getController' => \Quiote\Controller\Controller::class,
        'getTranslationManager' => \Quiote\Translation\TranslationManager::class,
        'getDatabaseManager' => \Quiote\Database\DatabaseManager::class,
    ];

    /**
     * @return     ?class-string
     * @since      4.0.0
     */
    protected function resolveInjectable(MethodCall $methodCall): ?string
    {
        foreach (self::ACCESSOR_INJECTIONS as $accessor => $injectable) {
            if ($this->contextCallAnalyzer->isContextCall($methodCall, $accessor)) {
                if ($methodCall->getArgs() !== []) {
                    // None of these accessors take arguments. A call that passes some is not the
                    // accessor this rule knows about, whatever it is named.
                    return null;
                }

                return $injectable;
            }
        }

        return null;
    }
}
