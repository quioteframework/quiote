<?php

declare(strict_types=1);

namespace Quiote\Docs;

use Quiote\Docs\Docblock\DocblockParser;
use Quiote\Docs\Docblock\ReferenceResolver;
use Quiote\Docs\Docblock\TypeResolver;
use Quiote\Docs\Docblock\ValueRenderer;
use Quiote\Docs\Ir\ApiIndex;
use Quiote\Docs\Ir\ClassDoc;
use Quiote\Docs\Ir\ConstantDoc;
use Quiote\Docs\Ir\DocBlock;
use Quiote\Docs\Ir\EnumCaseDoc;
use Quiote\Docs\Ir\InheritedMember;
use Quiote\Docs\Ir\MethodDoc;
use Quiote\Docs\Ir\ParamDoc;
use Quiote\Docs\Ir\PropertyDoc;
use Quiote\Docs\Ir\TypeRef;
use Quiote\Docs\Scan\ScannedType;
use Quiote\Support\Compiler\Diagnostic;

/**
 * Builds the documentation model from the types the scanner verified.
 *
 * This is the only place reflection is used: everything downstream works from the
 * {@see ApiIndex}, so an emitter can be tested against a model built by hand.
 */
final class ApiReflector
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function __construct(
        private readonly DocblockParser $docblocks = new DocblockParser(),
        private readonly TypeResolver $types = new TypeResolver(),
        private readonly ValueRenderer $values = new ValueRenderer(),
        private readonly ReferenceResolver $references = new ReferenceResolver(),
    ) {
    }

    /**
     * @param list<ScannedType> $scanned
     */
    public function build(array $scanned): ApiIndex
    {
        $this->diagnostics = [];
        $classes = [];

        foreach ($scanned as $type) {
            $class = $this->describe($type);
            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return new ApiIndex($this->withImplementers($classes));
    }

    /** @return list<Diagnostic> */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Parses a docblock and resolves the references in it against the file it came from.
     *
     * Every docblock goes through here rather than through the parser directly, so no prose
     * can reach a page still carrying an unresolved `{@see}`.
     */
    private function documentation(?string $docComment, ScannedType $context): DocBlock
    {
        return $this->references->resolve(
            $this->docblocks->parse($docComment),
            $context,
        );
    }

    private function describe(ScannedType $type): ?ClassDoc
    {
        $fqcn = $type->fqcn;

        // Safe here in a way it would not be against a path-derived name: the scanner proved
        // this file declares exactly this, so loading it defines what it says it does. A name
        // that still will not load belongs to a class whose parent is in a package that is not
        // installed, which is a real gap in the reference.
        if (
            !class_exists($fqcn)
            && !interface_exists($fqcn)
            && !trait_exists($fqcn)
            && !enum_exists($fqcn)
        ) {
            $this->diagnostics[] = new Diagnostic(
                Diagnostic::SEVERITY_WARNING,
                Diagnostic::CODE_UNRESOLVABLE_CLASS,
                'Could not load ' . $fqcn . '; it is missing from the reference.',
                $type->absolutePath,
                symbol: $fqcn,
            );

            return null;
        }

        $reflection = new \ReflectionClass($fqcn);

        $doc = $this->documentation($reflection->getDocComment() ?: null, $type);
        if ($doc->internal) {
            return null;
        }

        // On an abstract class or a trait the protected surface is the extension contract,
        // so it belongs in the reference; on a concrete class it is an implementation detail.
        $includeProtected = $reflection->isAbstract() || $reflection->isTrait();

        $parent = $reflection->getParentClass();
        $traitNames = $reflection->getTraitNames();
        sort($traitNames, SORT_STRING);

        $interfaceNames = $this->directInterfaces($reflection);

        return new ClassDoc(
            fqcn: $type->fqcn,
            shortName: $type->shortName,
            namespace: $type->namespace,
            kind: $type->kind,
            doc: $doc,
            sourcePath: $type->relativePath(),
            abstract: $reflection->isAbstract() && !$reflection->isInterface(),
            final: $reflection->isFinal(),
            readonly: $reflection->isReadOnly(),
            parent: $parent !== false ? TypeRef::named($parent->getName()) : null,
            interfaces: array_map(static fn(string $n): TypeRef => TypeRef::named($n), $interfaceNames),
            traits: array_map(static fn(string $n): TypeRef => TypeRef::named($n), $traitNames),
            constants: $this->constants($reflection, $type),
            cases: $this->cases($type),
            properties: $this->properties($reflection, $type, $includeProtected),
            constructor: $this->constructor($reflection, $type),
            methods: $this->methods($reflection, $type, $includeProtected),
            inheritedMethods: $this->inherited($reflection, $type),
            backingType: $this->backingType($type),
        );
    }

    /**
     * Interfaces this type declares itself, without the ones those interfaces bring along.
     *
     * `getInterfaceNames()` is the transitive closure, which makes an `implements` line read
     * as a list of everything in the hierarchy rather than what the author wrote.
     *
     * @param \ReflectionClass<object> $reflection
     * @return list<string>
     */
    private function directInterfaces(\ReflectionClass $reflection): array
    {
        $all = $reflection->getInterfaceNames();
        $inherited = [];

        $parent = $reflection->getParentClass();
        if ($parent !== false) {
            $inherited = $parent->getInterfaceNames();
        }

        foreach ($all as $name) {
            foreach ((new \ReflectionClass($name))->getInterfaceNames() as $nested) {
                $inherited[] = $nested;
            }
        }

        // Every enum implements these; listing them would say nothing about this one.
        if ($reflection->isEnum()) {
            $inherited[] = \UnitEnum::class;
            $inherited[] = \BackedEnum::class;
        }

        $direct = array_values(array_diff($all, $inherited));
        sort($direct, SORT_STRING);

        return $direct;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return list<ConstantDoc>
     */
    private function constants(\ReflectionClass $reflection, ScannedType $type): array
    {
        $constants = [];
        $enum = $this->asEnum($type);

        foreach ($reflection->getReflectionConstants() as $constant) {
            if (!$constant->isPublic() || $constant->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            // On an enum, every case is also reported as a class constant; the Cases section
            // documents those, so listing them again here would say the same thing twice.
            if ($enum !== null && $enum->hasCase($constant->getName())) {
                continue;
            }

            $doc = $this->documentation($constant->getDocComment() ?: null, $type);
            if ($doc->internal) {
                continue;
            }

            $constants[] = new ConstantDoc(
                name: $constant->getName(),
                value: $this->values->render($constant->getValue()),
                doc: $doc,
                final: $constant->isFinal(),
            );
        }

        usort($constants, static fn(ConstantDoc $a, ConstantDoc $b): int => strcmp($a->name, $b->name));

        return $constants;
    }

    /**
     * @return list<EnumCaseDoc>
     */
    private function cases(ScannedType $type): array
    {
        $enum = $this->asEnum($type);
        if ($enum === null) {
            return [];
        }

        $cases = [];

        foreach ($enum->getCases() as $case) {
            $backing = $case instanceof \ReflectionEnumBackedCase
                ? $this->values->render($case->getBackingValue())
                : null;

            $cases[] = new EnumCaseDoc(
                name: $case->getName(),
                backingValue: $backing,
                doc: $this->documentation($case->getDocComment() ?: null, $type),
            );
        }

        return $cases;
    }

    private function backingType(ScannedType $type): ?string
    {
        $backing = $this->asEnum($type)?->getBackingType();

        return $backing !== null ? (string) $backing : null;
    }

    /**
     * An enum's own reflection, which is the only view that reports its cases and backing
     * type; ReflectionClass sees just the methods the enum implementation contributes.
     *
     * @return \ReflectionEnum<\UnitEnum>|null
     */
    private function asEnum(ScannedType $type): ?\ReflectionEnum
    {
        if ($type->kind !== 'enum') {
            return null;
        }

        $fqcn = $type->fqcn;
        if (!enum_exists($fqcn)) {
            return null;
        }

        return new \ReflectionEnum($fqcn);
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return list<PropertyDoc>
     */
    private function properties(\ReflectionClass $reflection, ScannedType $type, bool $includeProtected): array
    {
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            if (!$this->visible($property->isPublic(), $property->isProtected(), $includeProtected)) {
                continue;
            }

            $doc = $this->documentation($property->getDocComment() ?: null, $type);
            if ($doc->internal) {
                continue;
            }

            $declared = $this->types->fromDocString($doc->returnType, $type);
            $properties[] = new PropertyDoc(
                name: $property->getName(),
                type: $declared ?? $this->types->fromReflection($property->getType(), $type),
                doc: $doc,
                visibility: $property->isProtected() ? 'protected' : 'public',
                static: $property->isStatic(),
                readonly: $property->isReadOnly(),
                promoted: $property->isPromoted(),
                default: $property->hasDefaultValue()
                    ? $this->values->render($property->getDefaultValue())
                    : null,
            );
        }

        usort($properties, static fn(PropertyDoc $a, PropertyDoc $b): int => strcmp($a->name, $b->name));

        return $properties;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private function constructor(\ReflectionClass $reflection, ScannedType $type): ?MethodDoc
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $reflection->getName()) {
            return null;
        }
        if (!$constructor->isPublic() && !$constructor->isProtected()) {
            return null;
        }

        return $this->method($constructor, $reflection, $type);
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return list<MethodDoc>
     */
    private function methods(\ReflectionClass $reflection, ScannedType $type, bool $includeProtected): array
    {
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            if ($method->getName() === '__construct') {
                continue;
            }
            if (!$this->visible($method->isPublic(), $method->isProtected(), $includeProtected)) {
                continue;
            }

            $described = $this->method($method, $reflection, $type);
            if ($described !== null) {
                $methods[] = $described;
            }
        }

        usort($methods, static fn(MethodDoc $a, MethodDoc $b): int => strcmp($a->name, $b->name));

        return $methods;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private function method(\ReflectionMethod $method, \ReflectionClass $reflection, ScannedType $type): ?MethodDoc
    {
        $doc = $this->documentation($method->getDocComment() ?: null, $type);
        if ($doc->internal) {
            return null;
        }

        if ($doc->inheritsDoc || $doc->isEmpty()) {
            $ancestor = $this->ancestorDoc($method, $reflection, $type);
            if ($ancestor !== null) {
                $doc = $doc->inheritFrom($ancestor);
            }
        }

        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $declared = $this->types->fromDocString($doc->paramTypes[$parameter->getName()] ?? null, $type);

            $parameters[] = new ParamDoc(
                name: $parameter->getName(),
                type: $declared ?? $this->types->fromReflection($parameter->getType(), $type),
                byReference: $parameter->isPassedByReference(),
                variadic: $parameter->isVariadic(),
                promoted: $parameter->isPromoted(),
                default: $this->values->forParameter($parameter),
                description: $doc->paramDescriptions[$parameter->getName()] ?? '',
            );
        }

        $declaredReturn = $this->types->fromDocString($doc->returnType, $type);

        return new MethodDoc(
            name: $method->getName(),
            parameters: $parameters,
            returnType: $declaredReturn ?? $this->types->fromReflection($method->getReturnType(), $type),
            doc: $doc,
            visibility: $method->isProtected() ? 'protected' : 'public',
            static: $method->isStatic(),
            abstract: $method->isAbstract(),
            final: $method->isFinal(),
            fromTrait: $this->traitProviding($method, $reflection),
        );
    }

    /**
     * The docblock of the same method on a parent or interface, for `{@inheritDoc}` and for
     * an override that documents only its tags.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private function ancestorDoc(\ReflectionMethod $method, \ReflectionClass $reflection, ScannedType $context): ?DocBlock
    {
        $candidates = [];

        $parent = $reflection->getParentClass();
        if ($parent !== false) {
            $candidates[] = $parent;
        }
        foreach ($reflection->getInterfaceNames() as $interface) {
            $candidates[] = new \ReflectionClass($interface);
        }

        foreach ($candidates as $candidate) {
            if (!$candidate->hasMethod($method->getName())) {
                continue;
            }

            $inherited = $candidate->getMethod($method->getName());
            $doc = $this->documentation($inherited->getDocComment() ?: null, $context);
            if (!$doc->isEmpty()) {
                return $doc;
            }
        }

        return null;
    }

    /**
     * The trait a method was composed in from, so a page can say where it came from.
     *
     * PHP reports a trait's method as declared on the using class, which is the behaviour a
     * reference wants -- the method really is part of that class's surface -- but readers
     * still need to know it is shared.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private function traitProviding(\ReflectionMethod $method, \ReflectionClass $reflection): ?string
    {
        foreach ($reflection->getTraits() as $trait) {
            if ($trait->hasMethod($method->getName())) {
                return $trait->getName();
            }
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     * @return list<InheritedMember>
     */
    private function inherited(\ReflectionClass $reflection, ScannedType $context): array
    {
        $members = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $declaring = $method->getDeclaringClass()->getName();
            if ($declaring === $reflection->getName() || str_starts_with($method->getName(), '__')) {
                continue;
            }

            $doc = $this->documentation($method->getDocComment() ?: null, $context);
            if ($doc->internal) {
                continue;
            }

            $members[] = new InheritedMember(
                name: $method->getName(),
                declaredIn: $declaring,
                summary: $doc->summary,
            );
        }

        usort($members, static fn(InheritedMember $a, InheritedMember $b): int => strcmp($a->name, $b->name));

        return $members;
    }

    private function visible(bool $isPublic, bool $isProtected, bool $includeProtected): bool
    {
        return $isPublic || ($isProtected && $includeProtected);
    }

    /**
     * Fills in each interface's list of implementers, which only becomes knowable once every
     * class has been described.
     *
     * @param list<ClassDoc> $classes
     * @return list<ClassDoc>
     */
    private function withImplementers(array $classes): array
    {
        $implementers = [];

        foreach ($classes as $class) {
            foreach ($class->interfaces as $interface) {
                if ($interface->fqcn !== null) {
                    $implementers[$interface->fqcn][] = $class->fqcn;
                }
            }
        }

        return array_map(
            static function (ClassDoc $class) use ($implementers): ClassDoc {
                $names = $implementers[$class->fqcn] ?? [];
                if ($names === []) {
                    return $class;
                }
                sort($names, SORT_STRING);

                return $class->withImplementedBy($names);
            },
            $classes,
        );
    }
}
