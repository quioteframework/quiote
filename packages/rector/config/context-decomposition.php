<?php

declare(strict_types=1);

use Quiote\Rector\Rector\ContextAccessorToConstructorInjectionRector;
use Quiote\Rector\Rector\ContextGetInstanceToRegistryRector;
use Quiote\Rector\Rector\ContextResidueReporter;
use Quiote\Rector\Rector\ContextGetModelToLocatorRector;
use Quiote\Rector\Rector\ContextRequestToRequestStateRector;
use Quiote\Rector\Rector\ContextServiceToConstructorInjectionRector;
use Rector\Config\RectorConfig;

/**
 * The Context-decomposition rule set.
 *
 * **Not published yet.** `quioteframework/rector` is developed in-tree and is listed for the subtree
 * split, but the standalone repo and its Packagist registration do not exist, so
 * `composer require --dev quioteframework/rector` cannot resolve outside this monorepo. Until it is
 * published, an application reaches these rules by pointing Composer at a path or VCS repository, or
 * by running them from a checkout of the monorepo.
 *
 * Once published, include this from an application's own rector.php:
 *
 *     $rectorConfig->import(__DIR__ . '/vendor/quioteframework/rector/config/context-decomposition.php');
 *
 * `importNames()` is set here rather than left to the caller: without it every injected dependency
 * is written fully qualified, which is correct but unreadable across hundreds of call sites.
 *
 * Rules land here as they are written and proven. All of rules 1, 2, 4, 5, 6 and the request half of 3 are present -- see the
 * plan's "Prove the rules first" gate. Running an incomplete set is safe; it simply leaves the accessors
 * it does not cover alone.
 */
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->importNames();

    $rectorConfig->rule(ContextServiceToConstructorInjectionRector::class);
    $rectorConfig->rule(ContextAccessorToConstructorInjectionRector::class);
    $rectorConfig->rule(ContextRequestToRequestStateRector::class);
    $rectorConfig->rule(ContextGetModelToLocatorRector::class);
    $rectorConfig->rule(ContextGetInstanceToRegistryRector::class);

    // Reports what the rules above could not touch. Rewrites nothing. Remove any previous
    // context-residue.txt first: the report appends, so stale lines read as current residue.
    $rectorConfig->rule(ContextResidueReporter::class);
};
