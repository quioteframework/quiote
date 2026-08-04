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
 * An application installs the rules with `composer require --dev quioteframework/rector` and either
 * points `--config` straight at this file or imports it from its own rector.php:
 *
 *     $rectorConfig->import(__DIR__ . '/vendor/quioteframework/rector/config/context-decomposition.php');
 *
 * Paths are deliberately not set here, so the caller chooses what to run against -- start with one
 * module rather than a whole application.
 *
 * `importNames()` is set here rather than left to the caller: without it every injected dependency
 * is written fully qualified, which is correct but unreadable across hundreds of call sites.
 *
 * The set does not cover every Context accessor. `getUser()`, `getTranslationManager()` and
 * `getDatabaseManager()` are left alone on purpose, because the correct rewrite for each depends on
 * a judgement the rules cannot make. Running a partial set is safe: an accessor no rule covers is
 * reported by ContextResidueReporter and otherwise untouched.
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
