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
 * ## Only two of the four the plan listed
 *
 * The plan grouped `getRouting`, `getTranslationManager`, `getDatabaseManager` and `getController`
 * together as "process lifetime, inject directly". Two of them are not safe to inject, and the
 * reason is specific rather than cautious:
 *
 * `Context::registerCoreService()` returns early when the instance is null, so a context with
 * translation or the database disabled never registers those services at all. Both classes are
 * *instantiable with zero required constructor arguments*, so the container does not fail on the
 * missing binding -- it autowires a brand-new, uninitialized one. That is the same silent
 * empty-instance defect that used to affect `WebRequest` and `User`, and here it is worse:
 * `getTranslationManager()` returns **null** when `core.use_translation` is off, so a call site
 * guarding with `?->` would have its guard rewritten away and then talk to a manager with no
 * locales loaded.
 *
 * `getRouting()` and `getController()` are required factories, always registered. `Routing` is also
 * abstract, so even a missing binding would fail loudly rather than silently.
 *
 * So `getTranslationManager()` and `getDatabaseManager()` are left for the residue reporter. They
 * are a smaller share of the sites than the two handled here, and the correct rewrite for them
 * depends on whether the call site tolerates null -- which is a judgement, not a substitution.
 *
 * @since      4.0.0
 */
final class ContextAccessorToConstructorInjectionRector extends AbstractContextInjectionRector
{
    /**
     * Accessor name => the class to inject for it.
     *
     * Deliberately not extended to the nullable, conditionally-registered accessors; see the class
     * docblock for why that is a correctness constraint and not a gap.
     *
     * @var        array<string, class-string>
     */
    private const array ACCESSOR_INJECTIONS = [
        'getRouting' => \Quiote\Routing\Routing::class,
        'getController' => \Quiote\Controller\Controller::class,
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
