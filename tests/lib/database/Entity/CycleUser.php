<?php

namespace Quiote\Test\Database\Entity;

/**
 * Plain entity for the Cycle integration test. Cycle maps it via a hand-written
 * schema array (see CycleIntegrationTest) rather than annotations, to avoid
 * pulling in cycle/annotated + cycle/schema-builder just for one test.
 */
class CycleUser
{
    public ?int $id = null;
    public string $name = '';
}
