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
     * FQCNs whose ordering constraints are guarantees rather than preferences: an
     * unresolvable `before`/`after` on one of these throws instead of degrading to
     * a diagnostic. See {@see MiddlewareOrderException}.
     * @var list<string>
     */
    private readonly array $guardedClasses;

    /**
     * @param ?list<string> $guardedClasses Defaults to the framework's own guarded set;
     *        pass an explicit list (including `[]`) to override, e.g. in unit tests that
     *        exercise the lenient path.
     */
    public function __construct(?array $guardedClasses = null)
    {
        // Resolved here rather than as a parameter default (PHP cannot call a method
        // there), so the safe behaviour is what you get by default and leniency has
        // to be asked for.
        $this->guardedClasses = $guardedClasses
            ?? \Quiote\Middleware\MiddlewarePipeline::guardedMiddlewareClasses();
    }

    /**
     * @param MiddlewareDefinition[] $definitions
     * @return MiddlewareDefinition[] Same definitions, reordered.
     * @throws MiddlewareOrderException if before/after constraints cycle, or if a
     *         guarded (framework) middleware's constraint cannot be resolved.
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
                return $this->unresolvable($from, $reference, self::CODE_UNRESOLVED_REFERENCE, 'is not among the scanned middleware');
            }
            return $fqcnMap[$reference];
        }

        if (!array_key_exists($reference, $shortNameMap)) {
            return $this->unresolvable($from, $reference, self::CODE_UNRESOLVED_REFERENCE, 'is not among the scanned middleware');
        }

        $index = $shortNameMap[$reference];
        if ($index === false) {
            return $this->unresolvable(
                $from,
                $reference,
                self::CODE_AMBIGUOUS_REFERENCE,
                'matches more than one scanned class (use a fully-qualified class name)'
            );
        }

        return $index;
    }

    /**
     * Record — or, for guarded middleware, refuse — a constraint that cannot be
     * resolved.
     *
     * Guarded middleware throws: a framework security middleware whose anchor has
     * gone missing must not quietly fall back to phase/priority ordering, because
     * the anchor is the only thing that made its position a guarantee. Everything
     * else keeps degrading to a diagnostic, since `before:` an optional package's
     * middleware is a legitimate thing to write and that package may not be
     * installed.
     *
     * @return null Always null, when it returns at all — the caller propagates that
     *         as "no constraint", so this exists to be used in a `return` position.
     * @throws MiddlewareOrderException If $from is a guarded class.
     */
    private function unresolvable(MiddlewareDefinition $from, string $reference, string $code, string $why): null
    {
        if (in_array($from->fqcn, $this->guardedClasses, true)) {
            throw MiddlewareOrderException::unresolvedGuardedReference($from->fqcn, $reference, $why);
        }

        $this->diagnostics[] = new Diagnostic(
            Diagnostic::SEVERITY_ERROR,
            $code,
            sprintf('Middleware "%s" references "%s", which %s; ignoring the constraint.', $from->fqcn, $reference, $why),
            $from->fqcn
        );

        return null;
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
