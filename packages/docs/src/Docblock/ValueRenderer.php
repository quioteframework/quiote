<?php

declare(strict_types=1);

namespace Quiote\Docs\Docblock;

/**
 * Renders a default value the way it would be written in source.
 *
 * `var_export()` is not usable here. It renders an object default as
 * `\Foo::__set_state(array(...))`, which is not what the parameter says, and it prints
 * floats at `serialize_precision`, so the same code produces different pages on two
 * machines with different ini settings.
 */
final class ValueRenderer
{
    /**
     * The default of a parameter, or null when it has none.
     *
     * A constant default is shown by name rather than by value, since the name is what the
     * caller would write. `getDefaultValueConstantName()` prefixes a global constant with the
     * declaring file's namespace -- `SEEK_SET` comes back as `Quiote\Http\Sse\SEEK_SET` -- so
     * a prefixed name that does not actually exist is reduced to its last segment.
     */
    public function forParameter(\ReflectionParameter $parameter): ?string
    {
        if (!$parameter->isDefaultValueAvailable()) {
            return null;
        }

        try {
            if ($parameter->isDefaultValueConstant()) {
                $name = $parameter->getDefaultValueConstantName();
                if ($name !== null) {
                    return $this->constantName($name);
                }
            }

            return $this->render($parameter->getDefaultValue());
        } catch (\Throwable) {
            // A default that cannot be read is better shown as unknown than not shown at all.
            return '…';
        }
    }

    private function constantName(string $name): string
    {
        if (defined($name) || str_contains($name, '::')) {
            return $name;
        }

        $short = str_contains($name, '\\')
            ? substr($name, (int) strrpos($name, '\\') + 1)
            : $name;

        return defined($short) ? $short : $name;
    }

    /** Renders any value that can appear as a default or a constant. */
    public function render(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->float($value),
            is_string($value) => $this->string($value),
            is_array($value) => $this->array($value),
            $value instanceof \UnitEnum => $this->enum($value),
            is_object($value) => 'new ' . $this->shortName($value::class) . '(…)',
            default => '…',
        };
    }

    private function float(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }

        // A float that is exactly integral still has to read as a float.
        $rendered = (string) $value;

        return str_contains($rendered, '.') || str_contains($rendered, 'E')
            ? $rendered
            : $rendered . '.0';
    }

    private function string(string $value): string
    {
        if ($value === '') {
            return "''";
        }

        // Long defaults are usually generated text; a page wants the shape, not the whole thing.
        if (mb_strlen($value) > 60) {
            $value = mb_substr($value, 0, 57) . '…';
        }

        return "'" . str_replace(["\\", "'", "\n"], ["\\\\", "\\'", '\n'], $value) . "'";
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function array(array $value): string
    {
        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $parts = [];

        foreach ($value as $key => $item) {
            if (count($parts) === 3) {
                $parts[] = '…';
                break;
            }
            $rendered = $this->render($item);
            $parts[] = $isList ? $rendered : $this->render($key) . ' => ' . $rendered;
        }

        return '[' . implode(', ', $parts) . ']';
    }

    private function enum(\UnitEnum $value): string
    {
        return $this->shortName($value::class) . '::' . $value->name;
    }

    private function shortName(string $fqcn): string
    {
        return str_contains($fqcn, '\\')
            ? substr($fqcn, (int) strrpos($fqcn, '\\') + 1)
            : $fqcn;
    }
}
