<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Support\Compiler\Diagnostic;

/** The result of {@see ResponseDiffer::diff()} for one replay. */
final readonly class DriftReport
{
    /** @param list<Diagnostic> $diagnostics */
    public function __construct(public array $diagnostics)
    {
    }

    public function isClean(): bool
    {
        return $this->diagnostics === [];
    }

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === Diagnostic::SEVERITY_ERROR) {
                return true;
            }
        }

        return false;
    }
}
