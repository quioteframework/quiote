<?php

declare(strict_types=1);

namespace Quiote\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Type\ObjectType;
use Quiote\Rector\NodeAnalyzer\ContextCallAnalyzer;
use Rector\NodeManipulator\ClassDependencyManipulator;
use Rector\PostRector\ValueObject\PropertyMetadata;
use Rector\Rector\AbstractRector;

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
final class ContextServiceToConstructorInjectionRector extends AbstractRector
{
    public function __construct(
        private readonly ContextCallAnalyzer $contextCallAnalyzer,
        private readonly ClassDependencyManipulator $classDependencyManipulator,
    ) {}

    /**
     * Refactors at class level rather than at the call, because injecting a dependency needs the
     * class: the constructor to add a parameter to, and the existing properties to avoid a name
     * collision with.
     *
     * @return     array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Class_ || $node->isAbstract() || $node->isAnonymous()) {
            // An anonymous class has no name to derive a property from and is usually a test
            // double; an abstract one may be extended by something that already injects.
            return null;
        }

        $injected = [];
        $this->traverseNodesWithCallable($node->stmts, function (Node $subNode) use (&$injected): ?Node {
            if (!$subNode instanceof MethodCall) {
                return null;
            }

            $serviceClass = $this->resolveServiceClass($subNode);
            if ($serviceClass === null) {
                return null;
            }

            $propertyName = $this->propertyNameFor($serviceClass, $injected);
            $injected[$serviceClass] = $propertyName;

            return new PropertyFetch(new Variable('this'), $propertyName);
        });

        if ($injected === []) {
            return null;
        }

        foreach ($injected as $serviceClass => $propertyName) {
            $this->classDependencyManipulator->addConstructorDependency(
                $node,
                new PropertyMetadata($propertyName, new ObjectType($serviceClass), Class_::MODIFIER_PRIVATE),
            );
        }

        return $node;
    }

    /**
     * The service class a `getService()` call names, or null when this is not such a call or the
     * argument is not a `::class` fetch.
     *
     * @since      4.0.0
     */
    private function resolveServiceClass(MethodCall $methodCall): ?string
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

        return $className === 'self' || $className === 'static' || $className === 'parent'
            ? null
            : $className;
    }

    /**
     * A property name for a service class: its short name, lower-camel-cased.
     *
     * Reuses the name already chosen for the same class in this pass, so two call sites for one
     * service share one injected property rather than adding two.
     *
     * @param      array<string, string> $injected Already-assigned class => property name.
     * @since      4.0.0
     */
    private function propertyNameFor(string $serviceClass, array $injected): string
    {
        if (isset($injected[$serviceClass])) {
            return $injected[$serviceClass];
        }

        $shortName = str_contains($serviceClass, '\\')
            ? substr($serviceClass, strrpos($serviceClass, '\\') + 1)
            : $serviceClass;

        $candidate = lcfirst($shortName);
        $taken = array_values($injected);

        // A distinct class whose short name collides with one already injected -- two different
        // TagService classes from different namespaces -- gets a suffix rather than silently
        // reusing the other one's property.
        $name = $candidate;
        $suffix = 2;
        while (in_array($name, $taken, true)) {
            $name = $candidate . $suffix++;
        }

        return $name;
    }
}
