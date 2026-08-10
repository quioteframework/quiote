<?php

declare(strict_types=1);

namespace Quiote\Util\FormPopulation;

/**
 * Turns an element's `name` attribute into the parameter path its value lives
 * under, resolving the empty brackets HTML uses for repeated fields.
 *
 * A form can name three inputs `tags[]`, and the browser submits them as a
 * list. Nothing in the markup says which one is index 0, so position in the
 * document decides -- which means resolving a name is stateful across the
 * elements of one form. That state is this object: one instance per form, fed
 * each element in document order.
 *
 * `foo[][3]` and the like are refused for checkable inputs, where `[]` carries
 * the separate meaning of "this is one of a group sharing a name" and so must
 * appear once, at the end.
 */
final class FieldNameResolver
{
    /** @var array<string, int> Highest index seen so far per repeated field. */
    private array $seenIndices = [];

    /**
     * Resolves one element's name, or null when the element should be skipped.
     */
    public function resolve(string $name, bool $isCheckable, bool $isMultipleSelect): ?ResolvedFieldName
    {
        $path = $name;
        $groupsByValue = false;

        if ($isCheckable) {
            $position = strpos($path, '[]');

            if ($position !== false && $position + 2 !== strlen($path)) {
                // foo[][3] and friends: for a checkable input "[]" means "one of a group",
                // so it has to be the whole of the trailing subscript.
                return null;
            }

            if ($position !== false) {
                $groupsByValue = true;
                $path = substr($path, 0, $position);
            }
        }

        if (preg_match_all('/([^\[]+)?(?:\[([^\]]*)\])/', $path, $matches)) {
            $path = $matches[1][0];

            // A multiple select submits a list under its own name, so its trailing
            // subscript belongs to the browser, not to the path.
            $subscriptCount = count($matches[2]) - ($isMultipleSelect ? 1 : 0);

            for ($i = 0; $i < $subscriptCount; ++$i) {
                $path .= '[' . $this->nextSubscript($path, $matches[2][$i]) . ']';
            }
        }

        return new ResolvedFieldName($path, $groupsByValue);
    }

    /**
     * The subscript to use for one bracket: the literal one when the markup
     * gives it, otherwise the next free index for this field.
     */
    private function nextSubscript(string $path, string $declared): int|string
    {
        $isNumeric = $declared === (string) (int) $declared;
        $value = $isNumeric ? (int) $declared : $declared;

        if (!isset($this->seenIndices[$path])) {
            $first = $declared !== '' ? $value : 0;
            if (is_int($first)) {
                $this->seenIndices[$path] = $first;
            }

            return $first;
        }

        if ($declared === '') {
            return ++$this->seenIndices[$path];
        }

        if (is_int($value) && $value > $this->seenIndices[$path]) {
            $this->seenIndices[$path] = $value;
        }

        return $value;
    }
}
