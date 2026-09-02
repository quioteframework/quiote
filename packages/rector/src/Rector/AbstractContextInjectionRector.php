<?php

declare(strict_types=1);

namespace Quiote\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Type\ObjectType;
use Quiote\Rector\NodeAnalyzer\ContextCallAnalyzer;
use Quiote\Rector\NodeAnalyzer\ExtendedClassIndex;
use Rector\NodeManipulator\ClassDependencyManipulator;
use Rector\PostRector\ValueObject\PropertyMetadata;
use Rector\Rector\AbstractRector;

/**
 * Shared machinery for the rules that replace a Context accessor with an injected collaborator.
 *
 * All of them do the same four things: find the accessor calls in a class, decide what to inject for
 * each, rewrite the calls to a property fetch, and add the constructor parameters. Only the third
 * step differs between rules, so only that is abstract.
 *
 * Rewriting happens at class level rather than at the call, because injecting a dependency needs the
 * class -- the constructor to add a parameter to, and the properties already there to avoid
 * colliding with.
 *
 * @since      4.0.0
 */
abstract class AbstractContextInjectionRector extends AbstractRector
{
    public function __construct(
        protected readonly ContextCallAnalyzer $contextCallAnalyzer,
        private readonly ClassDependencyManipulator $classDependencyManipulator,
        private readonly ExtendedClassIndex $extendedClassIndex,
    ) {}

    /**
     * @return     array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * Rewrites a class's Context accessor calls into fetches of injected properties.
     *
     * Walks the class body, asks the concrete rule what collaborator each accessor call
     * stands for, replaces the call with a property fetch and adds a private constructor
     * dependency per distinct collaborator, then repairs the synthesized constructor:
     * parameters whose property the parent already declares lose their promotion, and
     * the parameter order is normalised.
     *
     * Returns null -- leaving the class untouched -- for an abstract or anonymous class,
     * a class the rule does not consider injectable, a class something else extends
     * (adding a constructor parameter there breaks subclasses either way), and for a
     * class in which no accessor call was found.
     */
    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Class_ || $node->isAbstract() || $node->isAnonymous()) {
            // An anonymous class has no name to derive anything from and is usually a test double;
            // an abstract one may be extended by something that already injects what it needs.
            return null;
        }

        if (!$this->isInjectableClass($node)) {
            return null;
        }

        if ($this->isExtendedByAnything($node)) {
            // A constructor parameter added to a class others extend is not safe in either direction:
            // a subclass with its own constructor never forwards to the new one, and a subclass that
            // does forward breaks on the arity. See ExtendedClassIndex for the case that proved it.
            return null;
        }

        $this->prepare($node);

        /** @var array<string, string> $injected class => property name */
        $injected = [];

        $this->traverseNodesWithCallable($node->stmts, function (Node $subNode) use (&$injected, $node): ?Expr {
            if ($subNode instanceof StaticCall) {
                $injectable = $this->resolveInjectableForStaticCall($subNode);
                if ($injectable === null) {
                    return null;
                }

                $propertyName = $this->propertyNameFor($injectable, $injected, $node);
                $injected[$injectable] = $propertyName;

                return $this->buildReplacementForStaticCall($subNode, $propertyName);
            }

            if (!$subNode instanceof MethodCall) {
                return null;
            }

            $injectable = $this->resolveInjectable($subNode);
            if ($injectable === null) {
                return null;
            }

            $propertyName = $this->propertyNameFor($injectable, $injected, $node);
            $injected[$injectable] = $propertyName;

            return $this->buildReplacement($subNode, $propertyName);
        });

        if ($injected === []) {
            return null;
        }

        foreach ($injected as $injectableClass => $propertyName) {
            $this->classDependencyManipulator->addConstructorDependency(
                $node,
                new PropertyMetadata($propertyName, new ObjectType($injectableClass), Class_::MODIFIER_PRIVATE),
            );
        }

        $this->dropParentPromotedParams($node);
        $this->orderConstructorParams($node);

        return $node;
    }

    /**
     * Strip promotion from a constructor parameter whose property the parent already declares.
     *
     * When the class being rewritten has no constructor of its own, the dependency manipulator
     * synthesizes one -- and to keep the parent reachable it re-declares the parent's parameters and
     * forwards them, copying their promotion along with them:
     *
     *     public function __construct(protected readonly Context $context, private readonly Routing $routing)
     *     {
     *         parent::__construct($context);
     *     }
     *
     * That is a fatal. The child's promotion assigns `$this->context`, then `parent::__construct()`
     * assigns it a second time, and a readonly property cannot be written twice: the class
     * *declaration* compiles, and construction throws `Cannot modify readonly property`. Every
     * `Service` subclass would have been rewritten this way, because {@see \Quiote\Service\Service}
     * promotes its context.
     *
     * The repair is to leave the parameter in place but unpromoted, so the parent's constructor
     * remains the only writer of the property it owns.
     *
     * Only applied to a constructor that really does call `parent::__construct()`. Without that
     * call, dropping the promotion would drop the only assignment the property ever gets and trade
     * a loud fatal for a silently uninitialized collaborator.
     *
     * @since      4.0.0
     */
    private function dropParentPromotedParams(Class_ $class): void
    {
        $constructor = $class->getMethod('__construct');
        if ($constructor === null || $constructor->params === []) {
            return;
        }

        if (!$this->callsParentConstructor($constructor)) {
            return;
        }

        $promotedByParent = $this->parentPromotedParamNames($class);
        if ($promotedByParent === []) {
            return;
        }

        foreach ($constructor->params as $param) {
            if ($param->flags === 0 || !$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            if (in_array($param->var->name, $promotedByParent, true)) {
                $param->flags = 0;
            }
        }
    }

    /**
     * The names of the constructor parameters the declared parent promotes to properties.
     *
     * Read through reflection on the parent, which {@see isInjectableClass()} has already loaded by
     * the time any rewriting happens. The class being rewritten is not necessarily loadable itself,
     * but its parent always is.
     *
     * @return     array<int, string>
     * @since      4.0.0
     */
    private function parentPromotedParamNames(Class_ $class): array
    {
        if (!$class->extends instanceof Name) {
            return [];
        }

        $parent = $class->extends->toString();
        if (!class_exists($parent)) {
            return [];
        }

        $parentConstructor = (new \ReflectionClass($parent))->getConstructor();
        if ($parentConstructor === null) {
            return [];
        }

        $names = [];
        foreach ($parentConstructor->getParameters() as $parentParam) {
            if ($parentParam->isPromoted()) {
                $names[] = $parentParam->getName();
            }
        }

        return $names;
    }

    /**
     * Whether this constructor delegates to its parent's.
     *
     * @since      4.0.0
     */
    private function callsParentConstructor(ClassMethod $constructor): bool
    {
        $found = false;

        $this->traverseNodesWithCallable($constructor->stmts ?? [], function (Node $node) use (&$found): null {
            if (
                $node instanceof StaticCall
                && $node->class instanceof Name
                && $node->class->toLowerString() === 'parent'
                && $node->name instanceof Identifier
                && strcasecmp($node->name->toString(), '__construct') === 0
            ) {
                $found = true;
            }

            return null;
        });

        return $found;
    }

    /**
     * Move parameters without defaults ahead of parameters with them.
     *
     * The dependency manipulator appends, which produces invalid PHP as soon as the existing
     * constructor had an optional parameter:
     *
     *     public function __construct(array $parameters = [], private readonly Routing $routing)
     *
     * A required parameter cannot follow an optional one. Relative order is preserved within each
     * group, so the only movement is optional parameters shifting right.
     *
     * This does change the constructor's positional signature, which is safe here and would not be
     * everywhere: the classes these rules inject into are built by the container from their type
     * hints, not positionally. A class constructed with an explicit `new` and positional arguments
     * must not be rewritten at all -- see {@see isInjectableClass()}.
     *
     * @since      4.0.0
     */
    private function orderConstructorParams(Class_ $class): void
    {
        $constructor = $class->getMethod('__construct');
        if ($constructor === null || count($constructor->params) < 2) {
            return;
        }

        $required = [];
        $optional = [];
        foreach ($constructor->params as $param) {
            if ($param->default === null && !$param->variadic) {
                $required[] = $param;
            } else {
                $optional[] = $param;
            }
        }

        $reordered = [...$required, ...$optional];
        if ($reordered !== $constructor->params) {
            $constructor->params = $reordered;
        }
    }

    /**
     * Whether the container actually constructs this class, and so whether a constructor dependency
     * added to it would ever be supplied.
     *
     * This is not a refinement, it is a correctness requirement, and running the rules against the
     * framework is what surfaced it. `Quiote\Routing\HttpRedirectRoutingCallback` reaches
     * `getRouting()` and looks like an ideal candidate -- but `RoutingCallbackPool` builds callbacks
     * with a bare `new $className()`, so an injected parameter is never passed and the class fatals
     * on construction. The same is true of models, config handlers and most of the framework's own
     * infrastructure.
     *
     * Only four hierarchies are built through the container and may therefore be injected into.
     * Everything else is `new`'d by name somewhere, which is also what makes reordering the
     * constructor safe here and unsafe generally: a positional caller would be silently broken.
     *
     * Resolved from the declared parent rather than from the class itself. The class being rewritten
     * need not be autoloadable -- a Rector fixture never is, and PHPStan's reflection provider does
     * not know it -- but its *parent* is ordinary framework or application code that loads fine, and
     * `is_a()` on the parent walks the rest of the hierarchy. A class with no parent, or a parent that
     * cannot be loaded, is declined: "unknown" must not mean "assume injectable".
     *
     * @since      4.0.0
     */
    protected function isInjectableClass(Class_ $class): bool
    {
        if (!$class->extends instanceof Name) {
            return false;
        }

        $parent = $class->extends->toString();
        if (!class_exists($parent)) {
            return false;
        }

        foreach (self::CONTAINER_BUILT_HIERARCHIES as $base) {
            if (is_a($parent, $base, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class hierarchies the container constructs, and so the only ones a constructor dependency
     * can be added to. See {@see isInjectableClass()}.
     *
     * @var        array<int, class-string>
     */
    private const array CONTAINER_BUILT_HIERARCHIES = [
        \Quiote\Action\Action::class,
        \Quiote\View\View::class,
        \Quiote\Service\Service::class,
        \Quiote\Validator\Validator::class,
    ];

    /**
     * Whether something in the codebase extends the class being rewritten.
     *
     * Abstract classes are already declined in {@see refactor()}; this is about the concrete base
     * class, which is the shape that actually appears in applications -- an `AppBaseAction` or
     * `ApiBaseAction` sitting between the framework's class and the leaves, instantiable in principle
     * and extended in practice.
     *
     * @since      4.0.0
     */
    private function isExtendedByAnything(Class_ $class): bool
    {
        $name = $this->getName($class);

        return $name !== null && $this->extendedClassIndex->isExtended($name);
    }

    /**
     * Hook for work a rule needs to do once per class, before any call is examined.
     *
     * Rule 3 uses it to find the discarded-mutation statements: that has to be decided by looking
     * *down* from a statement, which is not possible from the call node alone.
     *
     * @since      4.0.0
     */
    protected function prepare(Class_ $class): void
    {
    }

    /**
     * The expression that replaces the rewritten call.
     *
     * Defaults to a bare property fetch, which is what the accessor-to-dependency rules want:
     * `getService(Foo::class)` becomes `$this->foo`. A rule whose injected collaborator is an
     * accessor rather than the collaborator itself overrides this -- `getRequest()` becomes
     * `$this->requestState->current()`, not `$this->requestState`.
     *
     * @since      4.0.0
     */
    protected function buildReplacement(MethodCall $methodCall, string $propertyName): Expr
    {
        return new PropertyFetch(new Variable('this'), $propertyName);
    }

    /**
     * The static-call equivalent of {@see resolveInjectable()}, for the one rule that rewrites a
     * static reach rather than an instance call.
     *
     * A separate hook rather than widening {@see resolveInjectable()}'s parameter, because widening
     * it would force every other rule to accept a node type it has nothing to say about -- and PHP's
     * contravariance rules would make each of them a fatal error until they did.
     *
     * @return     ?class-string
     * @since      4.0.0
     */
    protected function resolveInjectableForStaticCall(StaticCall $staticCall): ?string
    {
        return null;
    }

    /**
     * The expression that replaces a rewritten static call. See {@see buildReplacement()}.
     *
     * @since      4.0.0
     */
    protected function buildReplacementForStaticCall(StaticCall $staticCall, string $propertyName): Expr
    {
        return new PropertyFetch(new Variable('this'), $propertyName);
    }

    /**
     * The class to inject in place of this call, or null to leave the call alone.
     *
     * Returning null is the default answer and the safe one: a rule that cannot determine its target
     * with certainty must decline, so the site reaches the residue reporter instead of being
     * rewritten to a guess.
     *
     * @return     ?class-string
     * @since      4.0.0
     */
    abstract protected function resolveInjectable(MethodCall $methodCall): ?string;

    /**
     * A property name for an injected class: its short name, lower-camel-cased.
     *
     * Reuses the name already chosen for the same class in this pass, so several call sites for one
     * collaborator share one injected property rather than adding one each.
     *
     * Names the class already uses are avoided, and that is a correctness requirement rather than
     * tidiness. Rector's dependency manipulator *reuses* an existing property of the same name: an
     * action with its own `private ?string $user` had a `User` assigned into it and every one of its
     * own `$this->user` reads silently repointed at the injected user. `user` is the likeliest
     * collision of all the names these rules generate, but the same was true of `$routing` or
     * `$controller` in any class that happened to have one.
     *
     * Parents are consulted too, through the declared parent that {@see isInjectableClass()} has
     * already loaded: reusing an inherited name would shadow it rather than collide outright, which
     * is harder to notice.
     *
     * @param      array<string, string> $injected Already-assigned class => property name.
     * @since      4.0.0
     */
    protected function propertyNameFor(string $injectableClass, array $injected, ?Class_ $class = null): string
    {
        if (isset($injected[$injectableClass])) {
            return $injected[$injectableClass];
        }

        $shortName = str_contains($injectableClass, '\\')
            ? substr($injectableClass, strrpos($injectableClass, '\\') + 1)
            : $injectableClass;

        $candidate = lcfirst($shortName);
        $taken = [...array_values($injected), ...($class === null ? [] : $this->existingPropertyNames($class))];

        // Two distinct classes whose short names collide -- a TagService from two namespaces --
        // get separate properties rather than one quietly serving both.
        $name = $candidate;
        $suffix = 2;
        while (in_array($name, $taken, true)) {
            $name = $candidate . $suffix++;
        }

        return $name;
    }

    /**
     * Every property name this class already has: declared, promoted through its constructor, or
     * inherited. See {@see propertyNameFor()} for why inherited ones count.
     *
     * @return     array<int, string>
     * @since      4.0.0
     */
    private function existingPropertyNames(Class_ $class): array
    {
        $names = [];

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $propertyProperty) {
                $names[] = (string) $propertyProperty->name;
            }
        }

        $constructor = $class->getMethod('__construct');
        if ($constructor !== null) {
            foreach ($constructor->params as $param) {
                if ($param->flags !== 0 && $param->var instanceof Variable && is_string($param->var->name)) {
                    $names[] = $param->var->name;
                }
            }
        }

        if ($class->extends instanceof Name) {
            $parent = $class->extends->toString();
            if (class_exists($parent)) {
                foreach ((new \ReflectionClass($parent))->getProperties() as $parentProperty) {
                    $names[] = $parentProperty->getName();
                }
            }
        }

        return $names;
    }
}
