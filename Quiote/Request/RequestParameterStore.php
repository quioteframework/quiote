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
     */
    public function __construct(
        private readonly array $runtimeParameters = [],
        private readonly array $validatedKeys = [],
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

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->runtimeParameters);
    }

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

        // Support legacy bracket notation when tests or legacy code call setParameter.
        $firstBracket = strpos($name, '[');
        if ($firstBracket !== false) {
            $root = substr($name, 0, $firstBracket);
            if ($root !== '') {
                if (!array_key_exists($root, $runtimeParameters) || !is_array($runtimeParameters[$root])) {
                    $runtimeParameters[$root] = [];
                }
                if (preg_match_all('/\\[([^\\]]*)\\]/', $name, $matches)) {
                    $segments = $matches[1];
                    $current = &$runtimeParameters[$root];
                    $last = count($segments) - 1;
                    foreach ($segments as $i => $seg) {
                        if ($seg === '') {
                            $seg = (string)count($current);
                        }
                        if ($i === $last) {
                            $current[$seg] = $value;
                        } else {
                            if (!isset($current[$seg]) || !is_array($current[$seg])) {
                                $current[$seg] = [];
                            }
                            $current = &$current[$seg];
                        }
                    }
                    unset($current);
                    // Do not additionally store the fully qualified bracket path to avoid duplication.
                    $validatedKeys[$root] = true;
                    $validatedKeys[$name] = true;
                    return new self($runtimeParameters, $validatedKeys);
                }
            }
        }

        $runtimeParameters[$name] = $value;
        // Auto-whitelist: parameters set via setParameter() are explicitly provided by
        // application code (action validate methods, test helpers, etc.) and should be
        // accessible under strict validation. Without this, action validate() methods
        // that pass data to execute*() via setParameter() would be blocked.
        $validatedKeys[$name] = true;

        $store = new self($runtimeParameters, $validatedKeys);
        // If setting a root array (e.g. data => [[...]]), synthesize bracket keys (data[0][Field]).
        if (is_array($value) && $store->shouldMaterializeBracketPaths($name, $value)) {
            $store = $store->materializeBracketPaths($name, $value);
        }
        return $store;
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
        return new self($runtimeParameters, $this->validatedKeys);
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
        return new self($runtimeParameters, $this->validatedKeys);
    }

    /**
     * Remove a runtime parameter, including nested-path removal (best-effort).
     */
    public function withRemovedParameter(string $name): self
    {
        $runtimeParameters = $this->runtimeParameters;
        if (array_key_exists($name, $runtimeParameters)) {
            unset($runtimeParameters[$name]);
            return new self($runtimeParameters, $this->validatedKeys);
        }
        try {
            ArrayPathDefinition::unsetValue($name, $runtimeParameters);
        } catch (\Throwable) {
        }
        return new self($runtimeParameters, $this->validatedKeys);
    }

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
        return new self($this->runtimeParameters, $validatedKeys);
    }

    public function withDeclaredParameter(string $name): self
    {
        if ($name === '') {
            return $this;
        }
        $validatedKeys = $this->validatedKeys;
        $validatedKeys[$name] = true;
        return new self($this->runtimeParameters, $validatedKeys);
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
        return new self($this->runtimeParameters, $validatedKeys);
    }

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
     * @param array<int, string> $keep
     * @param array<int, string> $failed
     * @param array<array-key, bool> $preserve
     * @return self New store with only surviving runtime parameters retained.
     */
    public function pruneTo(array $keep, array $failed, array $preserve): self
    {
        $keepSet = [];
        foreach ($keep as $k) {
            $keepSet[$k] = true;
            // When a bracket-path like "Foo[Bar]" is validated, the root key "Foo"
            // must also be kept so nested arrays survive pruning.
            $firstBracket = strpos($k, '[');
            if ($firstBracket !== false) {
                $root = substr($k, 0, $firstBracket);
                if ($root !== '') {
                    $keepSet[$root] = true;
                }
            }
        }
        $failedSet = array_fill_keys($failed, true);

        $prunedRuntime = $this->runtimeParameters;
        foreach (array_keys($prunedRuntime) as $rName) {
            $remove = true;
            if (isset($keepSet[$rName])) {
                $remove = false;
            }
            if (isset($failedSet[$rName])) {
                $remove = true;
            }
            if (isset($preserve[$rName])) {
                $remove = false;
            }
            // Keep parameters that were explicitly whitelisted by validator exports.
            if (isset($this->validatedKeys[$rName])) {
                $remove = false;
            }
            if ($remove) {
                unset($prunedRuntime[$rName]);
            }
        }

        return new self($prunedRuntime, $this->validatedKeys);
    }

    /**
     * Decide whether to materialize bracket paths for a root key; avoid huge
     * structures (>200 elements) for performance.
     * @param array<mixed> $value
     */
    private function shouldMaterializeBracketPaths(string $root, array $value): bool
    {
        if ($root === '') {
            return false;
        }
        $first = reset($value);
        return is_array($first) && count($value) <= 200;
    }

    /**
     * For a structure like ['data' => [ ['Application' => 'orders', 'Enabled' => true] ] ]
     * create flattened bracketed entries: data[0][Application], data[0][Enabled].
     * @param array<int|string, mixed> $list
     */
    private function materializeBracketPaths(string $root, array $list): self
    {
        $runtimeParameters = $this->runtimeParameters;
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
        return new self($runtimeParameters, $this->validatedKeys);
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
