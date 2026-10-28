<?php

declare(strict_types=1);

namespace Quiote\Request;

use Quiote\Util\ArrayPathDefinition;

/**
 * Immutable holder for WebRequest's runtime (internal) parameters and the
 * strict-validation whitelist. This is the security enforcement core: only
 * parameters whitelisted in $validatedKeys may ever be read back out via
 * WebRequest::getParameter()/hasParameter().
 *
 * Every mutation returns a new instance. Callers (WebRequest) are expected to
 * replace their own reference with the returned store rather than relying on
 * in-place mutation.
 */
final class RequestParameterStore
{
    /**
     * @param array<array-key, mixed> $runtimeParameters
     * @param array<array-key, bool> $validatedKeys
     * @param array<array-key, bool> $wholeValueKeys Runtime parameters whose
     *        current value application code or a validator export set as a
     *        whole through {@see withParameter()}/{@see withParameters()}.
     *        Pruning trusts those as they are. A root built up by nested
     *        bracket-path writes, seeded from the submitted input, or staged
     *        unvalidated is not listed, so pruning cuts it down to the paths
     *        validation vouched for.
     */
    public function __construct(
        private readonly array $runtimeParameters = [],
        private readonly array $validatedKeys = [],
        private readonly array $wholeValueKeys = [],
    ) {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->runtimeParameters;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->runtimeParameters);
    }

    /**
     * Reports whether a runtime parameter of that exact name is present.
     *
     * A top-level key check only: nested bracket paths are not resolved, and
     * the whitelist is not consulted, so a caller enforcing strict validation
     * must still ask {@see isWhitelisted()}.
     */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->runtimeParameters);
    }

    /**
     * Returns the runtime parameter of that exact name, or null when it is not set.
     *
     * A top-level key lookup only, with no nested bracket-path resolution and
     * no whitelist check; a stored null is indistinguishable from a missing
     * parameter, so pair it with {@see has()} when that matters.
     */
    public function get(string $name): mixed
    {
        return $this->runtimeParameters[$name] ?? null;
    }

    /**
     * Legacy write API: set a runtime parameter (not an attribute, not HTTP input).
     */
    public function withParameter(string $name, mixed $value): self
    {
        $runtimeParameters = $this->runtimeParameters;
        $validatedKeys = $this->validatedKeys;
        $wholeValueKeys = $this->wholeValueKeys;
        self::applyParameter($runtimeParameters, $validatedKeys, $wholeValueKeys, $name, $value);
        return new self($runtimeParameters, $validatedKeys, $wholeValueKeys);
    }

    /**
     * Bulk counterpart to withParameter(): apply many runtime parameters in one
     * shot. Each key carries the same semantics (auto-whitelisting, bracket-path
     * notation, root-array materialization), but the runtime/whitelist arrays are
     * copied once and a single new store is constructed, instead of cloning the
     * store (and copying both arrays) once per key -- the O(n) allocation churn
     * the per-key setParameter() loop incurred on every request.
     * @param array<array-key, mixed> $params
     */
    public function withParameters(array $params): self
    {
        if ($params === []) {
            return $this;
        }
        $runtimeParameters = $this->runtimeParameters;
        $validatedKeys = $this->validatedKeys;
        $wholeValueKeys = $this->wholeValueKeys;
        foreach ($params as $name => $value) {
            self::applyParameter($runtimeParameters, $validatedKeys, $wholeValueKeys, (string) $name, $value);
        }
        return new self($runtimeParameters, $validatedKeys, $wholeValueKeys);
    }

    /**
     * Apply a single runtime parameter to the given runtime/whitelist arrays
     * in place. Shared by withParameter() and withParameters() so the mutation
     * semantics stay identical regardless of how many parameters are set at once.
     *
     * A bracketed name (`rows[0][ItemName]`) writes into the root array and
     * leaves every other key of it alone. A root that is not yet a runtime
     * parameter starts empty here; {@see WebRequest::setParameter()} seeds it
     * from the submitted input first, through {@see withSeededRoot()}.
     * @param array<array-key, mixed> $runtimeParameters
     * @param array<array-key, bool> $validatedKeys
     * @param array<array-key, bool> $wholeValueKeys
     */
    private static function applyParameter(array &$runtimeParameters, array &$validatedKeys, array &$wholeValueKeys, string $name, mixed $value): void
    {
        // Support legacy bracket notation when tests or legacy code call setParameter.
        $firstBracket = strpos($name, '[');
        if ($firstBracket !== false) {
            $root = substr($name, 0, $firstBracket);
            if ($root !== '' && preg_match_all('/\\[([^\\]]*)\\]/', $name, $matches)) {
                self::applyNestedParameter($runtimeParameters, $wholeValueKeys, $root, $matches[1], $value);
                // Do not additionally store the fully qualified bracket path to avoid duplication.
                $validatedKeys[$root] = true;
                $validatedKeys[$name] = true;
                return;
            }
        }

        $hadFlatKeys = self::dropFlatKeys($runtimeParameters, $name);
        $runtimeParameters[$name] = $value;
        $wholeValueKeys[$name] = true;
        // Auto-whitelist: parameters set via setParameter() are explicitly provided by
        // application code (action validate methods, test helpers, etc.) and should be
        // accessible under strict validation. Without this, action validate() methods
        // that pass data to execute*() via setParameter() would be blocked.
        $validatedKeys[$name] = true;

        // If setting a root array (e.g. data => [[...]]), synthesize bracket keys (data[0][Field]).
        if (is_array($value) && ($hadFlatKeys || self::shouldMaterializeBracketPaths($name, $value))) {
            self::materializeBracketPaths($runtimeParameters, $name, $value);
        }
    }

    /**
     * Writes $value at $segments inside root parameter $root, creating the
     * intermediate arrays it needs, and keeps the flattened `root[i][key]`
     * aliases of that root in step with the write.
     *
     * The root keeps its whole-value standing when it already had it; a root
     * this write creates is a nested-write root, which pruning cuts down.
     * @param array<array-key, mixed> $runtimeParameters
     * @param array<array-key, bool> $wholeValueKeys
     * @param array<int, string> $segments
     */
    private static function applyNestedParameter(array &$runtimeParameters, array &$wholeValueKeys, string $root, array $segments, mixed $value): void
    {
        if (!array_key_exists($root, $runtimeParameters) || !is_array($runtimeParameters[$root])) {
            $runtimeParameters[$root] = [];
            unset($wholeValueKeys[$root]);
        }
        if ($segments === []) {
            return;
        }

        $current = &$runtimeParameters[$root];
        $last = count($segments) - 1;
        $written = [];
        $previous = null;
        foreach ($segments as $i => $seg) {
            if ($seg === '') {
                $seg = (string) count($current);
            }
            $written[] = $seg;
            if ($i === $last) {
                $previous = $current[$seg] ?? null;
                $current[$seg] = $value;
            } else {
                if (!isset($current[$seg]) || !is_array($current[$seg])) {
                    $current[$seg] = [];
                }
                $current = &$current[$seg];
            }
        }
        unset($current);

        if (count($written) === 1) {
            // A whole element was replaced: its flattened fields follow it.
            $flatPrefix = $root . '[' . $written[0] . ']';
            $hadFlatKeys = false;
            if (is_array($previous)) {
                foreach (array_keys($previous) as $field) {
                    $flatKey = $flatPrefix . '[' . $field . ']';
                    if (array_key_exists($flatKey, $runtimeParameters)) {
                        unset($runtimeParameters[$flatKey]);
                        $hadFlatKeys = true;
                    }
                }
            }
            if ($hadFlatKeys && is_array($value)) {
                foreach ($value as $field => $fieldValue) {
                    $runtimeParameters[$flatPrefix . '[' . $field . ']'] = $fieldValue;
                }
            }
            return;
        }

        $flatKey = $root . '[' . $written[0] . '][' . $written[1] . ']';
        if (array_key_exists($flatKey, $runtimeParameters) && is_array($runtimeParameters[$root][$written[0]] ?? null)) {
            $runtimeParameters[$flatKey] = $runtimeParameters[$root][$written[0]][$written[1]] ?? null;
        }
    }

    /**
     * Removes the flattened `root[i][key]` aliases of the current value of
     * root parameter $root.
     * @param array<array-key, mixed> $runtimeParameters
     * @return bool Whether any alias was present.
     */
    private static function dropFlatKeys(array &$runtimeParameters, string $root): bool
    {
        $current = $runtimeParameters[$root] ?? null;
        if (!is_array($current)) {
            return false;
        }
        $dropped = false;
        foreach ($current as $index => $element) {
            if (!is_array($element)) {
                continue;
            }
            foreach (array_keys($element) as $field) {
                $flatKey = $root . '[' . $index . '][' . $field . ']';
                if (array_key_exists($flatKey, $runtimeParameters)) {
                    unset($runtimeParameters[$flatKey]);
                    $dropped = true;
                }
            }
        }

        return $dropped;
    }

    /**
     * Returns a copy with root parameter $root holding $value, the submitted
     * array a nested write is about to change.
     *
     * Neither whitelisted nor a whole value: the nested write that follows
     * whitelists the root, and pruning later cuts it down to the paths
     * validation vouched for. Does nothing when $root already is a runtime
     * parameter.
     * @param array<array-key, mixed> $value
     */
    public function withSeededRoot(string $root, array $value): self
    {
        if ($root === '' || array_key_exists($root, $this->runtimeParameters)) {
            return $this;
        }
        $runtimeParameters = $this->runtimeParameters;
        $runtimeParameters[$root] = $value;
        $wholeValueKeys = $this->wholeValueKeys;
        unset($wholeValueKeys[$root]);

        return new self($runtimeParameters, $this->validatedKeys, $wholeValueKeys);
    }

    /**
     * Sets a runtime parameter's value WITHOUT whitelisting it, unlike
     * withParameter(). Used for values that must be visible to validators
     * (e.g. a route param promoted into the pipeline so it can be validated
     * like any other input) but must not become readable via
     * WebRequest::getParameter() unless a real validator actually targets
     * that name -- the value sits in runtimeParameters (so
     * getParameters('parameters')'s pre-filter merge and a validator's
     * getKeysInCurrentBase() can see it), but isWhitelisted() stays false
     * until ValidationManager's own enforceValidatedParameters()/pruneTo()
     * decide it survived real validation.
     */
    public function withUnvalidatedParameter(string $name, mixed $value): self
    {
        $runtimeParameters = $this->runtimeParameters;
        $runtimeParameters[$name] = $value;
        $wholeValueKeys = $this->wholeValueKeys;
        unset($wholeValueKeys[$name]);
        return new self($runtimeParameters, $this->validatedKeys, $wholeValueKeys);
    }

    /**
     * Bulk counterpart to withUnvalidatedParameter(): apply many unvalidated
     * runtime parameters in one shot, copying the runtime array once instead
     * of once per key -- used to promote a whole batch of route params into
     * the pipeline (see ValidationMiddleware) without an O(n) clone loop.
     * @param array<array-key, mixed> $params
     */
    public function withUnvalidatedParameters(array $params): self
    {
        if ($params === []) {
            return $this;
        }
        $runtimeParameters = $this->runtimeParameters;
        $wholeValueKeys = $this->wholeValueKeys;
        foreach ($params as $name => $value) {
            $runtimeParameters[(string) $name] = $value;
            unset($wholeValueKeys[(string) $name]);
        }
        return new self($runtimeParameters, $this->validatedKeys, $wholeValueKeys);
    }

    /**
     * Legacy append API mirrors ParameterHolder::appendParameter semantics.
     */
    public function withAppendedParameter(string $name, mixed $value): self
    {
        $runtimeParameters = $this->runtimeParameters;
        if (!array_key_exists($name, $runtimeParameters) || !is_array($runtimeParameters[$name])) {
            $runtimeParameters[$name] = array_key_exists($name, $runtimeParameters)
                ? (array)$runtimeParameters[$name]
                : [];
        }
        $runtimeParameters[$name][] = $value;
        $wholeValueKeys = $this->wholeValueKeys;
        $wholeValueKeys[$name] = true;
        return new self($runtimeParameters, $this->validatedKeys, $wholeValueKeys);
    }

    /**
     * Remove a runtime parameter, including nested-path removal (best-effort).
     */
    public function withRemovedParameter(string $name): self
    {
        $runtimeParameters = $this->runtimeParameters;
        if (array_key_exists($name, $runtimeParameters)) {
            unset($runtimeParameters[$name]);
            $wholeValueKeys = $this->wholeValueKeys;
            unset($wholeValueKeys[$name]);
            return new self($runtimeParameters, $this->validatedKeys, $wholeValueKeys);
        }
        try {
            ArrayPathDefinition::unsetValue($name, $runtimeParameters);
        } catch (\Throwable $e) {
            // The parameter stays in the store, so a caller that asked for its removal can still
            // read it.
            \Quiote\Logging\Log::create(self::class)->warning(
                '[RequestParameterStore] could not remove parameter "' . $name . '": ' . $e->getMessage()
            );
        }
        return new self($runtimeParameters, $this->validatedKeys, $this->wholeValueKeys);
    }

    /**
     * Returns a copy with the parameter removed and its strict-validation
     * whitelist entry revoked.
     *
     * The counterpart of {@see withParameter()}, which whitelists a name as a
     * side effect of setting it. {@see withRemovedParameter()} undoes only the
     * value, leaving the name declared and therefore still readable; this
     * undoes both halves, so the name reads as never-declared again.
     */
    public function withRevokedParameter(string $name): self
    {
        $runtimeParameters = $this->runtimeParameters;
        unset($runtimeParameters[$name]);
        $validatedKeys = $this->validatedKeys;
        unset($validatedKeys[$name]);
        $wholeValueKeys = $this->wholeValueKeys;
        unset($wholeValueKeys[$name]);

        return new self($runtimeParameters, $validatedKeys, $wholeValueKeys);
    }

    /**
     * Returns a copy with every runtime parameter dropped.
     *
     * The strict-validation whitelist is kept, so names already declared stay
     * readable once they are set again.
     */
    public function withCleared(): self
    {
        return new self([], $this->validatedKeys);
    }

    /**
     * Mark the given request parameter names as declared (whitelisted for
     * strict-validation access).
     * @param string[] $names
     */
    public function withDeclaredParameters(array $names): self
    {
        $validatedKeys = $this->validatedKeys;
        foreach ($names as $name) {
            if ($name !== '') {
                $validatedKeys[$name] = true;
            }
        }
        return new self($this->runtimeParameters, $validatedKeys, $this->wholeValueKeys);
    }

    /**
     * Returns a copy with the given name whitelisted for strict-validation access.
     *
     * An empty name is ignored and this instance is returned unchanged. No
     * runtime value is created; the name merely becomes readable once one is.
     */
    public function withDeclaredParameter(string $name): self
    {
        if ($name === '') {
            return $this;
        }
        $validatedKeys = $this->validatedKeys;
        $validatedKeys[$name] = true;
        return new self($this->runtimeParameters, $validatedKeys, $this->wholeValueKeys);
    }

    /**
     * Define additional validated parameter names (expanding bracket-path
     * variants), merging into the existing whitelist.
     * @param array<int, string> $keys
     */
    public function withEnforcedValidatedParameters(array $keys): self
    {
        $validatedKeys = $this->validatedKeys;
        foreach ($keys as $key) {
            if ($key === '') {
                continue;
            }
            foreach (self::expandValidatedKeyVariants($key) as $variant) {
                $validatedKeys[$variant] = true;
            }
        }
        return new self($this->runtimeParameters, $validatedKeys, $this->wholeValueKeys);
    }

    /**
     * Reports whether the name may be read back out under strict validation.
     *
     * True when the name was declared verbatim, or when its numeric bracket
     * indices normalise onto a declared wildcard form -- so a validator
     * declaring `items[]` also covers `items[0]`, `items[1]` and so on.
     */
    public function isWhitelisted(string $name): bool
    {
        if (isset($this->validatedKeys[$name])) {
            return true;
        }
        $alias = self::normalizeNumericIndexKey($name);
        return $alias !== null && isset($this->validatedKeys[$alias]);
    }

    /**
     * Compute the keep/remove decision set for pruning: a name survives if
     * whitelisted directly, previously declared valid, or explicitly preserved
     * — but an explicit failure always wins.
     *
     * A surviving array is then cut down to the paths validation vouched for
     * (see {@see ParameterTreePruner}), unless it was preserved or set as a
     * whole by application code or a validator export. Flattened
     * `root[i][key]` aliases follow their root.
     * @param array<int, string> $keep
     * @param array<int, string> $failed
     * @param array<array-key, bool> $preserve
     * @return self New store with only surviving runtime parameters retained.
     */
    public function pruneTo(array $keep, array $failed, array $preserve): self
    {
        $tree = new ParameterTreePruner($keep, $failed);
        // A root that any validated path reaches survives; what survives inside it is
        // the tree pruner's decision.
        $keepSet = array_fill_keys($keep, true) + array_fill_keys($tree->roots(), true);
        $failedSet = array_fill_keys($failed, true);

        $prunedRuntime = $this->runtimeParameters;
        $wholeValueKeys = $this->wholeValueKeys;
        $aliases = [];
        foreach (array_keys($prunedRuntime) as $rName) {
            $name = (string) $rName;
            if (str_contains($name, '[')) {
                $aliases[] = $name;
                continue;
            }
            if ($this->shouldPrune($name, $keepSet, $failedSet, $preserve)) {
                self::dropFlatKeys($prunedRuntime, $name);
                unset($prunedRuntime[$name], $wholeValueKeys[$name]);
                continue;
            }
            $value = $prunedRuntime[$name];
            if (is_array($value) && !isset($preserve[$name]) && !isset($this->wholeValueKeys[$name])) {
                $hadFlatKeys = self::dropFlatKeys($prunedRuntime, $name);
                $prunedRuntime[$name] = $tree->prune($name, $value);
                if ($hadFlatKeys) {
                    self::materializeBracketPaths($prunedRuntime, $name, $prunedRuntime[$name]);
                }
            }
        }

        foreach ($aliases as $alias) {
            if (!array_key_exists($alias, $prunedRuntime)) {
                continue;
            }
            $root = substr($alias, 0, (int) strpos($alias, '['));
            $rootValue = $prunedRuntime[$root] ?? null;
            if (is_array($rootValue)) {
                // An alias of a surviving root lives exactly as long as the path it mirrors.
                if (!self::rootHasPath($root, $rootValue, $alias)) {
                    unset($prunedRuntime[$alias]);
                }
                continue;
            }
            if ($this->shouldPrune($alias, $keepSet, $failedSet, $preserve)) {
                unset($prunedRuntime[$alias]);
            }
        }

        return new self($prunedRuntime, $this->validatedKeys, $wholeValueKeys);
    }

    /**
     * The top-level keep/remove decision for one runtime parameter name.
     * @param array<array-key, bool> $keepSet
     * @param array<array-key, bool> $failedSet
     * @param array<array-key, bool> $preserve
     */
    private function shouldPrune(string $name, array $keepSet, array $failedSet, array $preserve): bool
    {
        // Last, so an explicit failure beats every reason to keep -- including the
        // whitelist. ValidationManager pre-declares the union of every validator's
        // argument names BEFORE running any of them, so validatedKeys always contains
        // the name of an argument that is about to fail; checking it after the failure
        // meant a value that failed validation stayed readable via
        // WebRequest::getParameter(). That hit runtime-staged values specifically -- a
        // route param promoted by ValidationMiddleware -- while the same value arriving
        // as a query or body param was correctly scrubbed by
        // WebRequest::pruneParametersToValidated().
        if (isset($failedSet[$name])) {
            return true;
        }

        // Keep parameters that were explicitly whitelisted by validator exports.
        return !isset($keepSet[$name]) && !isset($preserve[$name]) && !isset($this->validatedKeys[$name]);
    }

    /**
     * @param array<array-key, mixed> $rootValue
     */
    private static function rootHasPath(string $root, array $rootValue, string $path): bool
    {
        try {
            return ArrayPathDefinition::hasValue($path, [$root => $rootValue]);
        } catch (\InvalidArgumentException $e) {
            \Quiote\Logging\Log::create(self::class)->debug(
                '[RequestParameterStore] treating unparsable alias "' . $path . '" as absent: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Decide whether to materialize bracket paths for a root key; avoid huge
     * structures (>200 elements) for performance.
     * @param array<mixed> $value
     */
    private static function shouldMaterializeBracketPaths(string $root, array $value): bool
    {
        if ($root === '') {
            return false;
        }
        $first = reset($value);
        return is_array($first) && count($value) <= 200;
    }

    /**
     * For a structure like ['data' => [ ['Application' => 'orders', 'Enabled' => true] ] ]
     * create flattened bracketed entries: data[0][Application], data[0][Enabled],
     * writing them into the given runtime-parameter array in place.
     * @param array<array-key, mixed> $runtimeParameters
     * @param array<int|string, mixed> $list
     */
    private static function materializeBracketPaths(array &$runtimeParameters, string $root, array $list): void
    {
        foreach ($list as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $k => $v) {
                $flatKey = $root . '[' . $idx . '][' . $k . ']';
                // Do not overwrite if explicitly set already.
                if (!array_key_exists($flatKey, $runtimeParameters)) {
                    $runtimeParameters[$flatKey] = $v;
                }
            }
        }
    }

    /**
     * Expand a validated parameter name to include relevant base aliases.
     * For example "foo[]" will add both "foo[]" and "foo" to the whitelist.
     * @return array<int, string>
     */
    private static function expandValidatedKeyVariants(string $key): array
    {
        $variants = [$key => true];
        if (str_contains($key, '[')) {
            try {
                $partsInfo = ArrayPathDefinition::getPartsFromPath($key);
            } catch (\Throwable) {
                return array_keys($variants);
            }
            if (!empty($partsInfo['absolute']) && !empty($partsInfo['parts'])) {
                $root = $partsInfo['parts'][0];
                if ($root !== '') {
                    $variants[$root] = true;
                    $remainder = array_slice($partsInfo['parts'], 1);
                    if (isset($remainder[0]) && $remainder[0] === '') {
                        $variants[$root . '[]'] = true;
                    }
                }
            }
        }
        return array_keys($variants);
    }

    private static function normalizeNumericIndexKey(string $name): ?string
    {
        if (!str_contains($name, '[')) {
            return null;
        }
        try {
            $info = ArrayPathDefinition::getPartsFromPath($name);
        } catch (\Throwable) {
            return null;
        }
        $parts = $info['parts'];
        if (empty($parts)) {
            return null;
        }
        $updated = false;
        $normalizedParts = $parts;
        foreach ($normalizedParts as $idx => $segment) {
            if ($info['absolute'] && $idx === 0) {
                continue;
            }
            if ($segment === '') {
                continue;
            }
            $segmentStr = (string)$segment;
            if (ctype_digit($segmentStr)) {
                $normalizedParts[$idx] = '';
                $updated = true;
            }
        }
        if (!$updated) {
            return null;
        }
        $root = '';
        $tail = $normalizedParts;
        if ($info['absolute']) {
            $root = (string)$normalizedParts[0];
            $tail = array_slice($normalizedParts, 1);
        }
        $result = $root;
        if (!empty($tail)) {
            $result .= '[' . implode('][', $tail) . ']';
        }
        return $result;
    }
}
