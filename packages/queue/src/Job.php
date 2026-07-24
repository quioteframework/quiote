<?php

namespace Quiote\Queue;

/**
 * A unit of background work. Instantiated fresh per attempt via
 * {@see \Quiote\DI\Container::make()} — the same fresh-per-call autowiring
 * actions/views already get — so constructor-injected services autowire
 * normally and only the job's own arguments need to travel through the
 * queue as {@see JobPayload::$params}.
 */
interface Job
{
    public function handle(): void;
}
