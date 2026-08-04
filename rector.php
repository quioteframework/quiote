<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * Rector configuration for the framework's own tree.
 *
 * Phase 1 requires migrating the framework's own 271 Context call sites with the same rules the
 * application uses, and running them here first is how the rules get proven without needing the
 * application repo. See docs/CONTEXT_DECOMPOSITION_4_0_PLAN.md, "Prove the rules first".
 *
 * Deliberately no `paths()` default and no set imports: this is not a general-purpose modernisation
 * config, and nothing here should run across the tree by accident. Point it at a directory
 * explicitly, and start with --dry-run:
 *
 *     vendor/bin/rector process Quiote/Execution --dry-run
 */
return RectorConfig::configure()
    ->withPaths([
        // Intentionally empty. Pass a path on the command line.
    ])
    ->withSkip([
        // Fixtures are expected-output files, not code to migrate.
        __DIR__ . '/packages/rector/tests/*/Fixture/*',
        // Browser-context and span-context collisions live here; the rules resolve types and
        // should skip them anyway, but excluding them removes the question.
        __DIR__ . '/packages/telemetry-otel/tests/*',
    ])
    // Deliberately NOT ->withImportNames(). It is a global post-processor, not a per-rule setting:
    // enabling it here rewrote \Exception to Exception across 236 files, none of which this rule
    // touched. That churn would bury the actual migration diff. The cost is that injected
    // dependencies are written fully qualified; tidy them per-commit instead.
    ->withRules([
        \Quiote\Rector\Rector\ContextServiceToConstructorInjectionRector::class,
        \Quiote\Rector\Rector\ContextAccessorToConstructorInjectionRector::class,
        \Quiote\Rector\Rector\ContextRequestToRequestStateRector::class,
        \Quiote\Rector\Rector\ContextGetModelToLocatorRector::class,
        \Quiote\Rector\Rector\ContextGetInstanceToRegistryRector::class,
        \Quiote\Rector\Rector\ContextResidueReporter::class,
    ]);
