<?php
namespace Quiote\Request;

use Quiote\Request\Compiler\RequestDtoDefinition;
use Quiote\Request\Compiler\RequestDtoScanner;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * In-process cache for #[MapRequest] reflection results, mirroring
 * Quiote\DI\Container::classPlan()'s per-class caching style: a DTO's shape
 * never changes mid-process, so both the parsed RequestDtoDefinition and the
 * "which execute*() parameter (if any) is a #[MapRequest] DTO" lookup are
 * computed once and reused for the life of the worker/request. Unlike the
 * routing module's directory-wide scan, this reflects one class at a time,
 * so no filesystem-artifact pipeline is warranted here.
 * @since      1.0.0
 */
final class RequestDtoRegistry
{
    /** @var array<string, RequestDtoDefinition> */
    private static array $definitions = [];

    /** @var array<string, ?string> keyed by "{ActionClass}::{methodName}" */
    private static array $methodBindings = [];

    public static function definitionFor(string $dtoClass): RequestDtoDefinition
    {
        return self::$definitions[$dtoClass] ??= RequestDtoScanner::scan($dtoClass);
    }

    /**
     * The #[MapRequest] DTO class bound to the named action method's
     * parameter list, or null if that method has none.
     * @param class-string $actionClass
     */
    public static function dtoClassForMethod(string $actionClass, string $methodName): ?string
    {
        $key = $actionClass . '::' . $methodName;
        if (array_key_exists($key, self::$methodBindings)) {
            return self::$methodBindings[$key];
        }
        return self::$methodBindings[$key] = self::resolveDtoClassForMethod($actionClass, $methodName);
    }

    /**
     * @param class-string $actionClass
     */
    private static function resolveDtoClassForMethod(string $actionClass, string $methodName): ?string
    {
        if (!method_exists($actionClass, $methodName)) {
            return null;
        }
        $method = new ReflectionMethod($actionClass, $methodName);
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $typeName = $type->getName();
            if (RequestDtoScanner::isMapRequestDto($typeName)) {
                return $typeName;
            }
        }
        return null;
    }
}
