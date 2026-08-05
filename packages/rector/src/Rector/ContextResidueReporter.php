<?php

declare(strict_types=1);

namespace Quiote\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use Quiote\Rector\NodeAnalyzer\ContextCallAnalyzer;
use Quiote\Rector\Residue\ResidueReport;
use Rector\Rector\AbstractRector;

/**
 * Reports the Context call sites the rewriting rules cannot touch, with a reason for each.
 *
 * There is no static-call gap, despite an earlier commit here claiming one. Both halves work. What
 * looked like a rule that would not fire was {@see ResidueReport} overwriting its output once per
 * Rector worker process, so the file held one worker's partial view. That is fixed there, by
 * appending under a lock. The lesson is recorded because the false diagnosis was reached from absent
 * debug output, and STDERR from a Rector worker does not reach the terminal -- so absent output is
 * not evidence of absent execution.
 *
 * Does not rewrite. Its entire output is a report, so the remaining work is a finite list rather
 * than a discovery exercise -- which is the point: a migration that leaves an unknown quantity of
 * hand-work behind cannot be scheduled.
 *
 * Deliberately shares {@see ContextCallAnalyzer} with the rewriting rules, so what it reports is
 * exactly what they saw. A reporter with its own idea of "is this a Context call" would produce a
 * list that does not correspond to what was skipped, which is worse than no list.
 *
 * ## What it reports, and why silence is not an option
 *
 * A rule that declines a site leaves no trace. Nothing distinguishes "there were no sites here"
 * from "there were sites and every rule refused them", and the second is where the residual work
 * lives. So the reasons are enumerated rather than lumped into "unhandled":
 *
 * - `not-container-built` — the class is outside the four hierarchies the container constructs, so
 *   no dependency can be injected into it. Much the largest category in the framework's own tree.
 * - `nullable-accessor` — `getDatabaseConnection()`, whose replacement is a call on an injected
 *   database manager rather than the manager itself, so it is not a mapping entry in rule 2.
 * - `discarded-mutation` — a statement-level chain rooted in `getRequest()`. Already a no-op since
 *   the request became immutable; needs `FormPopulationConfig` and a `publish()`, which is a change
 *   of meaning.
 * - `unresolvable-argument` — `getService($id)` with a variable or a plain string, where the target
 *   would have to be guessed.
 * - `unhandled-accessor` — a Context accessor no rule covers yet. `getUser` stays on that list even
 *   though a rule handles it now: a site the rule rewrites is gone before this one sees it, so what
 *   remains is genuinely the sites it declined.
 * - `foreign-receiver` — shaped like a Context call, but the receiver resolves to a definite other
 *   class. Not work to do; work confirmed not to be needed, which a report has to distinguish from
 *   silence.
 * - `unresolved-receiver` — shaped like a Context call, and the receiver's type resolves to nothing at
 *   all, which in practice means an untyped `$context = null` parameter. Unlike the above, this one
 *   may well be work; it just cannot be decided without reading the call site.
 * - `not-an-accessor` — the receiver is a Context and the method is not one it declares, which in
 *   practice means a PHPUnit mock builder on a mocked Context.
 *
 * Written to `core.cache_dir`'s sibling by default, or wherever `QUIOTE_RECTOR_RESIDUE` points, on
 * process shutdown. A Rector rule has no reporting channel of its own, and printing into the diff
 * would be worse than a file.
 *
 * @since      4.0.0
 */
final class ContextResidueReporter extends AbstractRector
{
    /**
     * Accessors that are Context's but which no rewriting rule handles, with the reason.
     *
     * @var        array<string, string>
     */
    private const array UNHANDLED_ACCESSORS = [
        'getTranslationManager' => ResidueReport::REASON_UNHANDLED,
        'getDatabaseManager' => ResidueReport::REASON_UNHANDLED,
        'getDatabaseConnection' => ResidueReport::REASON_NULLABLE_ACCESSOR,
        'getUser' => ResidueReport::REASON_UNHANDLED,
        'getSessionBag' => ResidueReport::REASON_UNHANDLED,
        'getSessionManager' => ResidueReport::REASON_UNHANDLED,
        'setSessionBag' => ResidueReport::REASON_UNHANDLED,
        'setSessionManager' => ResidueReport::REASON_UNHANDLED,
        'createInstanceFor' => ResidueReport::REASON_UNHANDLED,
        'getFactoryInfo' => ResidueReport::REASON_UNHANDLED,
        'setFactoryInfo' => ResidueReport::REASON_UNHANDLED,
        'getCurrentPsrRequest' => ResidueReport::REASON_UNHANDLED,
        'getCorrelationId' => ResidueReport::REASON_UNHANDLED,
        'getSlotDispatcher' => ResidueReport::REASON_UNHANDLED,
        'getAssetRegistry' => ResidueReport::REASON_UNHANDLED,
        'getActionResolver' => ResidueReport::REASON_UNHANDLED,
        'getService' => ResidueReport::REASON_UNRESOLVABLE_ARGUMENT,
    ];

    public function __construct(
        private readonly ContextCallAnalyzer $contextCallAnalyzer,
        private readonly ResidueReport $residueReport,
    ) {}

    /**
     * @return     array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * Always returns null: this rule records and never changes a node.
     */
    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Class_) {
            return null;
        }

        $filePath = $this->file->getFilePath();
        $injectable = $this->isContainerBuilt($node);

        $this->traverseNodesWithCallable($node->stmts, function (Node $subNode) use ($filePath, $injectable): null {
            if ($subNode instanceof StaticCall) {
                $this->recordStaticCall($subNode, $filePath, $injectable);

                return null;
            }

            if ($subNode instanceof MethodCall) {
                $this->recordMethodCall($subNode, $filePath, $injectable);
            }

            return null;
        });

        return null;
    }

    private function recordMethodCall(MethodCall $methodCall, string $filePath, bool $injectable): void
    {
        $accessor = $methodCall->name instanceof Identifier ? $methodCall->name->toString() : '(dynamic)';

        if (!$this->contextCallAnalyzer->isAnyContextCall($methodCall)) {
            $this->recordLookalike($methodCall, $filePath, $accessor);

            return;
        }

        // The class cannot take an injected dependency at all, which subsumes every other reason:
        // no rule could have rewritten this whatever the accessor was.
        if (!$injectable) {
            $this->residueReport->add($filePath, $methodCall->getStartLine(), $accessor, ResidueReport::REASON_NOT_CONTAINER_BUILT);

            return;
        }

        foreach (self::UNHANDLED_ACCESSORS as $name => $reason) {
            if (strcasecmp($accessor, $name) === 0) {
                $this->residueReport->add($filePath, $methodCall->getStartLine(), $accessor, $reason);

                return;
            }
        }
    }

    /**
     * Record a call that looks like a Context call but is not one.
     *
     * Two shapes qualify, and both are named in the report rather than passed over. A rule that
     * declines a site leaves no trace, so a reader cannot tell "the rules considered this and were
     * right to refuse" from "the rules never looked here" -- and the whole value of the report is
     * that the remaining work is a known quantity.
     *
     * A call is only a lookalike if its *receiver* reads like a Context: a `getContext()` call, or a
     * variable or property named `context`. Keying on the method name instead was tried and produces
     * a useless report -- `getName()` is a Context method and also a method on routes, output types,
     * locales and half the tree, so 241 ordinary calls were reported in the framework alone. A report
     * nobody can work through is no better than silence.
     *
     * @since      4.0.0
     */
    private function recordLookalike(MethodCall $methodCall, string $filePath, string $accessor): void
    {
        if (!$this->isContextShapedReceiver($methodCall->var)) {
            return;
        }

        $this->residueReport->add($filePath, $methodCall->getStartLine(), $accessor, $this->lookalikeReason($methodCall));
    }

    /**
     * Which of the three lookalike answers applies.
     *
     * The receiver being a real Context means the method name is what disqualified the call -- a mock
     * builder, or something else Context does not declare. Otherwise it comes down to whether the
     * receiver resolves to a *definite* other class, which is confirmed not to be work, or to nothing
     * resolvable, which nobody can decide from here.
     *
     * @since      4.0.0
     */
    private function lookalikeReason(MethodCall $methodCall): string
    {
        if ($this->contextCallAnalyzer->isContextExpr($methodCall->var)) {
            return ResidueReport::REASON_NOT_AN_ACCESSOR;
        }

        return $this->contextCallAnalyzer->receiverClassNames($methodCall->var) === []
            ? ResidueReport::REASON_UNRESOLVED_RECEIVER
            : ResidueReport::REASON_FOREIGN_RECEIVER;
    }

    /**
     * Whether an expression is written the way a Context is usually reached, whatever it turns out to
     * be: `$this->getContext()`, `$context`, or `$this->context`.
     *
     * @since      4.0.0
     */
    private function isContextShapedReceiver(Node\Expr $expr): bool
    {
        if ($expr instanceof MethodCall) {
            return $expr->name instanceof Identifier && strcasecmp($expr->name->toString(), 'getContext') === 0;
        }

        if ($expr instanceof Node\Expr\Variable) {
            return is_string($expr->name) && strcasecmp($expr->name, 'context') === 0;
        }

        if ($expr instanceof Node\Expr\PropertyFetch) {
            return $expr->name instanceof Identifier && strcasecmp($expr->name->toString(), 'context') === 0;
        }

        return false;
    }

    private function recordStaticCall(StaticCall $staticCall, string $filePath, bool $injectable): void
    {
        if (!$staticCall->name instanceof Identifier
            || strcasecmp($staticCall->name->toString(), 'getInstance') !== 0
            || !$staticCall->class instanceof Name) {
            return;
        }

        $className = $staticCall->class->toString();
        if (in_array($className, ['self', 'static', 'parent'], true)
            || !class_exists($className)
            || !is_a($className, \Quiote\Context::class, true)) {
            return;
        }

        if (!$injectable) {
            $this->residueReport->add($filePath, $staticCall->getStartLine(), 'Context::getInstance', ResidueReport::REASON_NOT_CONTAINER_BUILT);
        }
    }

    /**
     * Mirrors {@see AbstractContextInjectionRector::isInjectableClass()} -- see there for why the
     * parent is asked rather than the class.
     */
    private function isContainerBuilt(Class_ $class): bool
    {
        if (!$class->extends instanceof Name) {
            return false;
        }

        $parent = $class->extends->toString();
        if (!class_exists($parent)) {
            return false;
        }

        foreach ([
            \Quiote\Action\Action::class,
            \Quiote\View\View::class,
            \Quiote\Service\Service::class,
            \Quiote\Validator\Validator::class,
        ] as $base) {
            if (is_a($parent, $base, true)) {
                return true;
            }
        }

        return false;
    }
}
