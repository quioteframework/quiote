<?php

declare(strict_types=1);

namespace Quiote\Docs\Docblock;

use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Quiote\Docs\Ir\TypeRef;
use Quiote\Docs\Scan\ScannedType;

/**
 * Builds {@see TypeRef} trees from what the source says, resolving names the way PHP would.
 *
 * A docblock writes `Route`, not `Quiote\Routing\Route`; only the declaring file's `use`
 * statements say which `Route` that is. Reflection cannot supply them, which is why the
 * scanner collects them and they arrive here as the {@see ScannedType} context.
 */
final class TypeResolver
{
    private readonly TypeParser $parser;
    private readonly Lexer $lexer;

    public function __construct()
    {
        $config = new ParserConfig(usedAttributes: []);
        $this->lexer = new Lexer($config);
        $this->parser = new TypeParser($config, new ConstExprParser($config));
    }

    /** Converts a native parameter, property or return type. */
    public function fromReflection(?\ReflectionType $type, ScannedType $context): TypeRef
    {
        if ($type === null) {
            return TypeRef::literal('mixed');
        }

        if ($type instanceof \ReflectionUnionType) {
            return TypeRef::union(array_values(array_map(
                fn(\ReflectionType $member): TypeRef => $this->fromReflection($member, $context),
                $type->getTypes(),
            )));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return TypeRef::intersection(array_values(array_map(
                fn(\ReflectionType $member): TypeRef => $this->fromReflection($member, $context),
                $type->getTypes(),
            )));
        }

        if (!$type instanceof \ReflectionNamedType) {
            return TypeRef::literal((string) $type);
        }

        $resolved = $type->isBuiltin()
            ? TypeRef::literal($type->getName())
            : TypeRef::named($type->getName());

        return $type->allowsNull() && $type->getName() !== 'null' && $type->getName() !== 'mixed'
            ? TypeRef::nullable($resolved)
            : $resolved;
    }

    /**
     * Converts a type as written in a docblock, which is usually the narrower of the two:
     * `list<Route>` where the signature could only say `array`.
     */
    public function fromDocString(?string $type, ScannedType $context): ?TypeRef
    {
        if ($type === null || trim($type) === '') {
            return null;
        }

        try {
            $tokens = new TokenIterator($this->lexer->tokenize($type));
            $node = $this->parser->parse($tokens);
        } catch (\Throwable) {
            return TypeRef::literal($type);
        }

        return $this->fromNode($node, $context);
    }

    private function fromNode(TypeNode $node, ScannedType $context): TypeRef
    {
        if ($node instanceof NullableTypeNode) {
            return TypeRef::nullable($this->fromNode($node->type, $context));
        }

        if ($node instanceof UnionTypeNode) {
            return TypeRef::union(array_values(array_map(
                fn(TypeNode $member): TypeRef => $this->fromNode($member, $context),
                $node->types,
            )));
        }

        if ($node instanceof IntersectionTypeNode) {
            return TypeRef::intersection(array_values(array_map(
                fn(TypeNode $member): TypeRef => $this->fromNode($member, $context),
                $node->types,
            )));
        }

        if ($node instanceof GenericTypeNode) {
            return TypeRef::generic(
                $this->fromNode($node->type, $context),
                array_values(array_map(
                    fn(TypeNode $arg): TypeRef => $this->fromNode($arg, $context),
                    $node->genericTypes,
                )),
            );
        }

        if ($node instanceof ArrayTypeNode) {
            $inner = $this->fromNode($node->type, $context);

            return TypeRef::generic(TypeRef::literal('array'), [$inner]);
        }

        if ($node instanceof ArrayShapeNode) {
            // A shape is rendered whole rather than linked: its keys are the interesting part,
            // and a page shows them in a table beside the signature.
            return TypeRef::literal((string) $node);
        }

        if ($node instanceof IdentifierTypeNode) {
            return $this->identifier($node->name, $context);
        }

        return TypeRef::literal((string) $node);
    }

    /**
     * Resolves one bare identifier.
     *
     * A name starting lowercase is a keyword or a phpstan pseudo-type -- `int`, `list`,
     * `non-empty-string`, `self` -- and never a class to link at. Anything else goes through
     * the file's imports, then its own namespace, exactly as PHP resolves it.
     */
    private function identifier(string $name, ScannedType $context): TypeRef
    {
        if ($name === '') {
            return TypeRef::literal('mixed');
        }

        if (str_starts_with($name, '\\')) {
            return TypeRef::named(ltrim($name, '\\'));
        }

        if (!ctype_upper($name[0])) {
            return TypeRef::literal($name);
        }

        $first = str_contains($name, '\\') ? strstr($name, '\\', true) : $name;
        $rest = str_contains($name, '\\') ? substr($name, strlen((string) $first)) : '';

        $imported = $context->resolveImport((string) $first);
        if ($imported !== null) {
            return TypeRef::named($imported . $rest);
        }

        return TypeRef::named(
            $context->namespace !== '' ? $context->namespace . '\\' . $name : $name,
        );
    }
}
