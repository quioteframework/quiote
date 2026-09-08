<?php

declare(strict_types=1);

namespace Quiote\Environment;

/**
 * Something that knows about a distinct, enumerable set of environment names --
 * e.g. the object-store-backed replay cassette store's env-partitioned key
 * scheme. A plugin registers one via {@see \Quiote\Plugin\PluginRegistrar::environmentSource()}
 * so `env:list` (and anything else that wants to know "what environments exist")
 * doesn't need to know about any specific package's own storage scheme.
 */
interface EnvironmentSourceInterface
{
    /** A short label identifying what this source enumerates, shown next to its results. */
    public function label(): string;

    /** @return list<string> */
    public function listEnvironments(): array;
}
