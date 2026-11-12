<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Request\RequestState;
use Quiote\View\View;

final class EditInputView extends View
{
    public function __construct(private readonly RequestState $requestState) {}

    public function migrated(array $values): void
    {
        // What a port of the Agavi idiom turns into: no Context call left, and still a no-op,
        // because setAttribute() returns a new request that nothing keeps.
        $this->requestState->current()->setAttribute('populate', ['integrationForm' => $values]);
    }

    public function namespaced(array $values): void
    {
        $this->requestState->current()?->setAttribute('populate', ['integrationForm' => $values], 'org.agavi.filter.FormPopulationFilter');
    }

    public function kept(array $values): mixed
    {
        // The result is used, so this is somebody's deliberate code rather than a dead statement.
        return $this->requestState->current()->setAttribute('populate', ['integrationForm' => $values]);
    }

    public function otherAttribute(): void
    {
        $this->requestState->current()->setAttribute('something-else', true);
    }
}
