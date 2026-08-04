<?php

declare(strict_types=1);

namespace Quiote\Rector\NodeAnalyzer;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Rector\NodeTypeResolver\NodeTypeResolver;

/**
 * Decides whether a method call actually reaches a Quiote {@see \Quiote\Context}.
 *
 * Every rewriting rule in this package goes through here, because `getContext()` and `$context`
 * are not distinctive names and getting this wrong corrupts unrelated code. The framework's own
 * tree contains, right now:
 *
 * - `$span->getContext()->isValid()` and `->getTraceId()` — OpenTelemetry span contexts, one of
 *   them in first-party source (`Quiote\Telemetry\Trace`), not just tests.
 * - `$context->getRows()` — a terminal dashboard render context.
 * - `$context->method('getName')` — a **PHPUnit mock builder** on a mocked Context. This one is
 *   the reason type resolution alone is not enough: the receiver genuinely *is* a `Context`
 *   (as `MockObject&Context`), so only the method name distinguishes it.
 *
 * So the test has two halves that must both hold: the receiver resolves to a Quiote Context,
 * **and** the method being called is one Context actually declares. A rule written as "rewrite
 * any call on a Context-typed receiver" would rewrite mock setup into nonsense.
 *
 * @since      4.0.0
 */
final readonly class ContextCallAnalyzer
{
    /**
     * The context contract and its concrete class. An application's own subclass resolves to one
     * of these by inheritance, so subclasses need no enumeration.
     */
    private const array CONTEXT_TYPES = [
        'Quiote\\Context',
        'Quiote\\ContextInterface',
    ];

    /**
     * Method names a rule may rewrite: everything `Context` declares that a call site legitimately
     * reaches. Deliberately an allowlist rather than "anything not on a denylist" -- a new method
     * on Context should not silently become rewritable, and a name that is not here (`method()`
     * from a mock builder, `getRows()` from something else entirely) is never touched.
     */
    private const array CONTEXT_METHODS = [
        'createInstanceFor',
        'getActionResolver',
        'getAssetRegistry',
        'getContainer',
        'getController',
        'getCorrelationId',
        'getCurrentPsrRequest',
        'getDatabaseConnection',
        'getDatabaseManager',
        'getFactoryInfo',
        'getModel',
        'getName',
        'getRequest',
        'getRouting',
        'getService',
        'getSessionBag',
        'getSessionManager',
        'getSlotDispatcher',
        'getTranslationManager',
        'getUser',
        'setFactoryInfo',
        'setRequest',
        'setSessionBag',
        'setSessionManager',
    ];

    public function __construct(private NodeTypeResolver $nodeTypeResolver) {}

    /**
     * Whether $methodCall is a call to $methodName on something that really is a Quiote Context.
     *
     * Case-insensitive on the method name, because PHP is: the reference application contains
     * `getTranslationmanager()` with a lowercase m, and a case-sensitive match would skip it and
     * leave behind a call to a method that no longer exists.
     *
     * @since      4.0.0
     */
    public function isContextCall(MethodCall $methodCall, string $methodName): bool
    {
        if (!$this->isNamed($methodCall, $methodName)) {
            return false;
        }

        return $this->isContextExpr($methodCall->var);
    }

    /**
     * Whether $methodCall calls any rewritable Context method on a real Context.
     *
     * For the residue reporter, which needs "this is a Context call we did not handle" without
     * caring which accessor it was.
     *
     * @since      4.0.0
     */
    public function isAnyContextCall(MethodCall $methodCall): bool
    {
        if (!$methodCall->name instanceof Identifier) {
            // A dynamic name -- $context->$accessor() -- is unresolvable, so it is residue rather
            // than something to rewrite.
            return false;
        }

        foreach (self::CONTEXT_METHODS as $candidate) {
            if ($this->isNamed($methodCall, $candidate)) {
                return $this->isContextExpr($methodCall->var);
            }
        }

        return false;
    }

    /**
     * Whether an expression evaluates to a Quiote Context.
     *
     * Resolves through PHPStan, so `$this->getContext()`, a `Context`-typed parameter, a property
     * and an application's own Context subclass are all recognised, and an unrelated object with a
     * same-named method is not.
     *
     * @since      4.0.0
     */
    public function isContextExpr(Expr $expr): bool
    {
        return $this->isContextType($this->nodeTypeResolver->getType($expr));
    }

    /**
     * Whether a resolved type is a Quiote Context.
     *
     * Union and intersection types are handled by asking PHPStan for acceptance rather than
     * unwrapping them: a mocked Context resolves to `MockObject&Context`, which *is* a Context and
     * must be recognised as one -- the mock builder's `method()` call is excluded by name, not by
     * pretending the receiver is something else.
     *
     * @since      4.0.0
     */
    public function isContextType(Type $type): bool
    {
        foreach (self::CONTEXT_TYPES as $contextType) {
            if ((new ObjectType($contextType))->isSuperTypeOf($type)->yes()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class name a resolved type names, or null when it names none or more than one.
     *
     * Asked of the type rather than tested with instanceof: PHPStan deprecated inspecting its type
     * objects directly, and a union resolving to several classes has no single answer to give.
     *
     * @since      4.0.0
     */
    public function classNameOf(Type $type): ?string
    {
        $classNames = $type->getObjectClassNames();

        return count($classNames) === 1 ? $classNames[0] : null;
    }

    /**
     * The method names a rule may rewrite.
     *
     * @return     array<int, string>
     * @since      4.0.0
     */
    public static function rewritableMethods(): array
    {
        return self::CONTEXT_METHODS;
    }

    /**
     * Case-insensitive method-name match. See {@see isContextCall()} for why.
     *
     * @since      4.0.0
     */
    private function isNamed(MethodCall $methodCall, string $methodName): bool
    {
        return $methodCall->name instanceof Identifier
            && strcasecmp($methodCall->name->toString(), $methodName) === 0;
    }
}
