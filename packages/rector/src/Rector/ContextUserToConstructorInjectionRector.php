<?php

declare(strict_types=1);

namespace Quiote\Rector\Rector;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use Quiote\User\CurrentUser;
use Quiote\User\User;

/**
 * `Context::getUser()` to an injected user, or to an injected {@see CurrentUser} where holding one
 * would be wrong.
 *
 * ```php
 * // before, in an action, view or validator
 * $this->getContext()->getUser()->getCompany();
 * // after
 * $this->user->getCompany();
 *
 * // before, in a service
 * $this->getContext()->getUser()->getCompany();
 * // after
 * $this->currentUser->get()->getCompany();
 * ```
 *
 * ## Why two targets and not one
 *
 * The user is stable within a request -- it is replaced only at the worker request boundary and by
 * the pre-request deferral, never mid-request -- so an object that lives for exactly one execution
 * can hold it. Actions, views and validators are built per execution, so for them the direct
 * injection is both correct and the better read at the call site.
 *
 * A service cannot, and the reason is not its own scope but its *holder's*. A singleton that holds
 * the user serves request 1's identity to every later request in a persistent worker. The container
 * refuses that wiring outright -- `User` is bound at request scope, so the captive-dependency guard
 * sees it -- which means a rule that injected `User` into a singleton service would produce code
 * that throws at wiring time rather than code that leaks. But a *transient* service is no safer in
 * practice: nothing stops a singleton from holding one, and the guard cannot see through that
 * indirection to the user captured inside it.
 *
 * So every `Service` subclass gets {@see CurrentUser}, whatever scope it declares. That is what
 * `CurrentUser` exists for, it resolves through to the context on every call and memoizes nothing,
 * and it is correct at every scope -- which is why this rule does not read `#[Service(scope: ...)]`
 * at all. The plan expected that analysis to be a prerequisite and expected service subclasses to
 * land in the residue report; choosing a per-call resolver for them removes both.
 *
 * ## Why `User` and not `SecurityUser` or `ISecurityUser`
 *
 * `Context::getUser()` answers `User|ISecurityUser`, and a union cannot be a constructor type hint.
 * `User` is the one type every user is, and {@see \Quiote\Context::SEAM_CONTRACTS} binds it to the
 * request's real instance -- so the type hint resolves to the application's own subclass rather than
 * to a fresh empty stranger. An application reaching its own subclass's methods narrows the hint by
 * hand afterwards; a wider-than-needed hint is a static-analysis note, while a narrower one would be
 * a wrong binding.
 *
 * @since      4.0.0
 */
final class ContextUserToConstructorInjectionRector extends AbstractContextInjectionRector
{
    /**
     * What to inject for the class currently being rewritten, decided once per class from its
     * lifetime rather than per call site: every call in one class resolves the same way.
     *
     * @var        ?class-string
     */
    private ?string $injectable = null;

    /**
     * The hierarchies built and discarded per execution, which may therefore hold the user.
     *
     * `Service` is deliberately absent -- see the class docblock.
     *
     * @var        array<int, class-string>
     */
    private const array PER_EXECUTION_HIERARCHIES = [
        \Quiote\Action\Action::class,
        \Quiote\View\View::class,
        \Quiote\Validator\Validator::class,
    ];

    #[\Override]
    protected function prepare(Class_ $class): void
    {
        $this->injectable = $this->isPerExecutionHolder($class) ? User::class : CurrentUser::class;
    }

    /**
     * @return     ?class-string
     * @since      4.0.0
     */
    #[\Override]
    protected function resolveInjectable(MethodCall $methodCall): ?string
    {
        if (!$this->contextCallAnalyzer->isContextCall($methodCall, 'getUser')) {
            return null;
        }

        if ($methodCall->getArgs() !== []) {
            // getUser() takes none. Something else is being called, whatever it is named.
            return null;
        }

        return $this->injectable;
    }

    /**
     * @since      4.0.0
     */
    #[\Override]
    protected function buildReplacement(MethodCall $methodCall, string $propertyName): Expr
    {
        $property = new PropertyFetch(new Variable('this'), $propertyName);

        // CurrentUser is an accessor onto the user, not the user -- so the call site keeps a call.
        return $this->injectable === CurrentUser::class
            ? new MethodCall($property, 'get')
            : $property;
    }

    /**
     * Whether this class is built and discarded per execution, and so may hold the request's user.
     *
     * Resolved from the declared parent for the same reason {@see isInjectableClass()} does it that
     * way: the class being rewritten need not be loadable, but its parent always is.
     *
     * @since      4.0.0
     */
    private function isPerExecutionHolder(Class_ $class): bool
    {
        if (!$class->extends instanceof Name) {
            return false;
        }

        $parent = $class->extends->toString();
        if (!class_exists($parent)) {
            return false;
        }

        foreach (self::PER_EXECUTION_HIERARCHIES as $base) {
            if (is_a($parent, $base, true)) {
                return true;
            }
        }

        return false;
    }
}
