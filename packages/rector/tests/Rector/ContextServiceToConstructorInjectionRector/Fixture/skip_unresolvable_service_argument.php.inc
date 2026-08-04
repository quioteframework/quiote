<?php

namespace Quiote\Rector\Tests\Fixture;

use Quiote\Context;

final class UnresolvableServiceArgument
{
    public function run(Context $context, string $id): mixed
    {
        // The target would have to be guessed. Guessing injects the wrong
        // collaborator silently, so these go to the residue reporter instead.
        $fromVariable = $context->getService($id);
        $fromString = $context->getService('tag_service');

        return [$fromVariable, $fromString];
    }
}
