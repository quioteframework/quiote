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
 * **NOT REGISTERED YET. Known gap: static calls are not reported.**
 *
 * The instance-call half works and was run against the framework: 25 sites, all
 * `not-container-built`, which corroborates from the other direction why every rewriting rule finds
 * nothing there. The static half does not fire at all -- `Quiote\Config\ConfigCache` has two real
 * `Context::getInstance()` calls and neither is recorded, and `recordStaticCall()` is not reached.
 * Not yet diagnosed.
 *
 * Left unregistered because of what this rule is for. A reporter that silently omits a whole
 * category produces a list that reads as complete and is not, which is worse than no list: the
 * entire argument for having it is that silence must not be mistaken for "no sites here". Fix the
 * static path, verify it against `ConfigCache`, then register it.
 *
 * (A caution for whoever picks this up: rule 5 shares this class-name check, and its zero-changes
 * result against the framework was attributed to the container-built guard. Confirm that is
 * actually why, and not the same gap.)
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
 * - `nullable-accessor` — `getTranslationManager()`/`getDatabaseManager()`, which are null when
 *   their subsystem is off and whose classes autowire to a fresh empty instance if injected.
 * - `discarded-mutation` — a statement-level chain rooted in `getRequest()`. Already a no-op since
 *   the request became immutable; needs `FormPopulationConfig` and a `publish()`, which is a change
 *   of meaning.
 * - `unresolvable-argument` — `getService($id)` with a variable or a plain string, where the target
 *   would have to be guessed.
 * - `unhandled-accessor` — a Context accessor no rule covers yet.
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
        'getTranslationManager' => ResidueReport::REASON_NULLABLE_ACCESSOR,
        'getDatabaseManager' => ResidueReport::REASON_NULLABLE_ACCESSOR,
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
        if (!$this->contextCallAnalyzer->isAnyContextCall($methodCall)) {
            return;
        }

        $accessor = $methodCall->name instanceof Identifier ? $methodCall->name->toString() : '(dynamic)';

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
