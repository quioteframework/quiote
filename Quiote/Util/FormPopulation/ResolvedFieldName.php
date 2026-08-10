<?php

declare(strict_types=1);

namespace Quiote\Util\FormPopulation;

/**
 * A form element's name, resolved to the parameter path its value lives under.
 *
 * `$groupsByValue` marks a checkable input whose name ended in `[]`: the
 * submitted parameter is then a list of the *values* that were checked, so
 * whether this element is checked is decided by looking for its own value in
 * that list rather than by comparing the parameter to it.
 */
final readonly class ResolvedFieldName
{
    public function __construct(public string $path, public bool $groupsByValue) {}
}
