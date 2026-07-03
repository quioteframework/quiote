<?php
declare(strict_types=1);

namespace Quiote\Middleware\Compiler;

use Quiote\Support\Compiler\Diagnostic;

/**
 * Turns scanned `MiddlewareDefinition`s into a single pipeline order.
 *
 * `phase` (see MiddlewarePhase::ORDER) is the primary grouping; within/across
 * phases, explicit `before`/`after` edges are hard constraints (a Kahn
 * topological sort), and `priority` (higher runs earlier) plus scan order
 * break remaining ties. `before`/`after` may name either a short class name
 * (e.g. "RoutingMiddleware", matching how the framework's own attributes are
 * written today) or a fully-qualified class name.
 * @since      1.0.0
 */
final class MiddlewareOrderResolver
{
    public const CODE_AMBIGUOUS_REFERENCE = 'AMBIGUOUS_REFERENCE';
    public const CODE_UNRESOLVED_REFERENCE = 'UNRESOLVED_REFERENCE';

    /** @var Diagnostic[] */
    private array $diagnostics = [];

    /**
     * @param MiddlewareDefinition[] $definitions
     * @return MiddlewareDefinition[] Same definitions, reordered.
     * @throws MiddlewareOrderException if before/after constraints cycle.
     */
    public function resolve(array $definitions): array
    {
        $this->diagnostics = [];
        $definitions = array_values($definitions);
        $count = count($definitions);
        if ($count === 0) {
            return $definitions;
        }

        $shortNameMap = $this->buildShortNameMap($definitions);
        $fqcnMap = [];
        foreach ($definitions as $i => $definition) {
            $fqcnMap[$definition->fqcn] = $i;
        }

        // successors[$i] = list of node indexes that must come after node $i
        $successors = array_fill(0, $count, []);
        $indegree = array_fill(0, $count, 0);

        foreach ($definitions as $i => $definition) {
            if ($definition->after !== null) {
                $target = $this->resolveReference($definition->after, $definition, $shortNameMap, $fqcnMap);
                if ($target !== null && $target !== $i) {
                    $successors[$target][] = $i;
                    $indegree[$i]++;
                }
            }
            if ($definition->before !== null) {
                $target = $this->resolveReference($definition->before, $definition, $shortNameMap, $fqcnMap);
                if ($target !== null && $target !== $i) {
                    $successors[$i][] = $target;
                    $indegree[$target]++;
                }
            }
        }

        return $this->topologicalSort($definitions, $successors, $indegree);
    }

    /** @return Diagnostic[] Diagnostics recorded during the last resolve(). */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param MiddlewareDefinition[] $definitions
     * @return array<string,int|false> short name => index, or false if ambiguous
     */
    private function buildShortNameMap(array $definitions): array
    {
        $map = [];
        foreach ($definitions as $i => $definition) {
            $short = $definition->shortName();
            $map[$short] = array_key_exists($short, $map) ? false : $i;
        }
        return $map;
    }

    /**
     * @param array<string,int|false> $shortNameMap
     * @param array<string,int> $fqcnMap
     */
    private function resolveReference(string $reference, MiddlewareDefinition $from, array $shortNameMap, array $fqcnMap): ?int
    {
        if (str_contains($reference, '\\')) {
            if (!array_key_exists($reference, $fqcnMap)) {
                $this->diagnostics[] = new Diagnostic(
                    Diagnostic::SEVERITY_ERROR,
                    self::CODE_UNRESOLVED_REFERENCE,
                    sprintf('Middleware "%s" references unknown middleware "%s"; ignoring the constraint.', $from->fqcn, $reference),
                    $from->fqcn
                );
                return null;
            }
            return $fqcnMap[$reference];
        }

        if (!array_key_exists($reference, $shortNameMap)) {
            $this->diagnostics[] = new Diagnostic(
                Diagnostic::SEVERITY_ERROR,
                self::CODE_UNRESOLVED_REFERENCE,
                sprintf('Middleware "%s" references unknown middleware "%s"; ignoring the constraint.', $from->fqcn, $reference),
                $from->fqcn
            );
            return null;
        }

        $index = $shortNameMap[$reference];
        if ($index === false) {
            $this->diagnostics[] = new Diagnostic(
                Diagnostic::SEVERITY_ERROR,
                self::CODE_AMBIGUOUS_REFERENCE,
                sprintf('Middleware "%s" references "%s", which matches more than one scanned class; use a fully-qualified class name.', $from->fqcn, $reference),
                $from->fqcn
            );
            return null;
        }

        return $index;
    }

    /**
     * @param MiddlewareDefinition[] $definitions
     * @param array<int,int[]> $successors
     * @param array<int,int> $indegree
     * @return MiddlewareDefinition[]
     */
    private function topologicalSort(array $definitions, array $successors, array $indegree): array
    {
        $count = count($definitions);
        $emitted = array_fill(0, $count, false);
        $available = [];
        foreach ($indegree as $i => $deg) {
            if ($deg === 0) {
                $available[] = $i;
            }
        }

        $sortKey = fn(int $i) => [MiddlewarePhase::rank($definitions[$i]->phase), -$definitions[$i]->priority, $i];

        $result = [];
        while (!empty($available)) {
            $bestPos = 0;
            $bestKey = $sortKey($available[0]);
            foreach ($available as $pos => $idx) {
                $key = $sortKey($idx);
                if ($key < $bestKey) {
                    $bestKey = $key;
                    $bestPos = $pos;
                }
            }

            $current = $available[$bestPos];
            array_splice($available, $bestPos, 1);
            $emitted[$current] = true;
            $result[] = $definitions[$current];

            foreach ($successors[$current] as $next) {
                $indegree[$next]--;
                if ($indegree[$next] === 0) {
                    $available[] = $next;
                }
            }
        }

        if (count($result) !== $count) {
            $remaining = [];
            foreach ($emitted as $i => $done) {
                if (!$done) {
                    $remaining[] = $definitions[$i]->fqcn;
                }
            }
            throw MiddlewareOrderException::cycle($remaining);
        }

        return $result;
    }
}
