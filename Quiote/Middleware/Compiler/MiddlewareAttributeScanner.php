<?php
declare(strict_types=1);

namespace Quiote\Middleware\Compiler;

use Psr\Http\Server\MiddlewareInterface;
use Quiote\Middleware\Attribute\Middleware;
use Quiote\Support\Compiler\Diagnostic;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Reflects a list of candidate classes for `#[Middleware]` attributes and
 * builds the `MiddlewareDefinition`s that `MiddlewareOrderResolver` sorts
 * into a pipeline order. Modeled on
 * `Quiote\Routing\Compiler\AttributeRouteScanner`, but takes an explicit FQCN
 * list rather than globbing directories: unlike actions (which always live
 * under a module's `Actions/` tree), middleware has no established directory
 * convention to scan, so candidates are whatever the framework's own core
 * list plus `MiddlewareCatalog::getAttributedCandidates()` supply.
 *
 * A class is only a candidate for scanning if it's already been supplied by
 * the caller — this scanner never does its own class discovery. Classes
 * without a `#[Middleware]` attribute, or that don't implement
 * `MiddlewareInterface`, are silently skipped (the same "presence is opt-in"
 * rule `AttributeRouteScanner` uses for `#[Route]`).
 * @since      1.0.0
 */
final class MiddlewareAttributeScanner
{
    public const CODE_NOT_A_MIDDLEWARE = 'NOT_A_MIDDLEWARE';
    public const CODE_DUPLICATE_CANDIDATE = 'DUPLICATE_CANDIDATE';
    public const CODE_CLASS_NOT_FOUND = 'CLASS_NOT_FOUND';

    /** @var Diagnostic[] */
    private array $diagnostics = [];

    /**
     * @param iterable<string> $candidateFqcns
     * @return MiddlewareDefinition[]
     */
    public function scan(iterable $candidateFqcns): array
    {
        $this->diagnostics = [];

        /** @var array<string,true> $seen */
        $seen = [];
        $definitions = [];

        foreach ($candidateFqcns as $fqcn) {
            if (isset($seen[$fqcn])) {
                $this->diagnostics[] = new Diagnostic(
                    Diagnostic::SEVERITY_WARNING,
                    self::CODE_DUPLICATE_CANDIDATE,
                    sprintf('Middleware class "%s" was supplied as a scan candidate more than once.', $fqcn),
                    $fqcn
                );
                continue;
            }
            $seen[$fqcn] = true;

            if (!class_exists($fqcn)) {
                $this->diagnostics[] = new Diagnostic(
                    Diagnostic::SEVERITY_ERROR,
                    self::CODE_CLASS_NOT_FOUND,
                    sprintf('Middleware candidate class "%s" does not exist.', $fqcn),
                    $fqcn
                );
                continue;
            }

            $reflection = new ReflectionClass($fqcn);

            if (!$reflection->implementsInterface(MiddlewareInterface::class)) {
                $this->diagnostics[] = new Diagnostic(
                    Diagnostic::SEVERITY_ERROR,
                    self::CODE_NOT_A_MIDDLEWARE,
                    sprintf('Middleware candidate class "%s" does not implement %s.', $fqcn, MiddlewareInterface::class),
                    $fqcn
                );
                continue;
            }

            $attributes = $reflection->getAttributes(Middleware::class, ReflectionAttribute::IS_INSTANCEOF);
            if (!$attributes) {
                continue;
            }

            /** @var Middleware $attribute */
            $attribute = $attributes[0]->newInstance();
            $definitions[] = new MiddlewareDefinition(
                $fqcn,
                $attribute->phase,
                $attribute->priority,
                $attribute->before,
                $attribute->after,
                $attribute->enabled,
                $fqcn
            );
        }

        return $definitions;
    }

    /** @return Diagnostic[] Diagnostics recorded during the last scan(). */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }
}
