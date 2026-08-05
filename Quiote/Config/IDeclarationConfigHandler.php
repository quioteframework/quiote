<?php

declare(strict_types=1);

namespace Quiote\Config;

/**
 * A config handler whose compiled artifact is a declaration -- data -- plus the code that applies
 * that data to runtime state.
 *
 * {@see ConfigCache::load()} reads the artifact's value and hands it to {@see apply()}. The artifact
 * itself never runs statements, so a poisoned cache entry can only produce wrong configuration,
 * never execution: the code that acts on the declaration is this class, shipped with the framework
 * or the package, not a string in the cache.
 *
 * A declaration arrives from the cache or from a hand-authored `.php`/`.yaml` source, so {@see apply()}
 * is a trust boundary: validate the shape and throw
 * {@see \Quiote\Exception\ConfigurationException} rather than assuming what the compiler produced.
 *
 * @since      4.0.0
 */
interface IDeclarationConfigHandler
{
    /**
     * Apply a compiled declaration to runtime state.
     *
     * @param      mixed $declaration The value the compiled configuration returned.
     * @param      string $sourceRef The configuration file the declaration came from, for diagnostics.
     * @return     void
     * @throws     \Quiote\Exception\ConfigurationException If the declaration is not the shape this
     *                                                     handler compiles.
     * @since      4.0.0
     */
    public function apply(mixed $declaration, string $sourceRef): void;
}
