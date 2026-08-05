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
 *
 * ## Two things that make a census silently lie
 *
 * **Always pass `--clear-cache`.** Rector's result cache is on by default, so a second run over paths
 * that have not changed reports *nothing at all* -- the rules never execute, and the residue reporter
 * never sees a site. An empty report then reads as "no work left" when it means "not looked at".
 * `composer rector:census` does this for you.
 *
 * **Running against an application, run the rules from here.** An application that depends on
 * `quioteframework/rector` has its own vendored copy, and its autoloader wins over this one -- so
 * `vendor/bin/rector` inside the application silently exercises the *published* rules, not the
 * working tree's. Invoke this repo's binary with the application's autoloader added instead:
 *
 *     vendor/bin/rector process ../app/src/Modules/Thing \
 *         --autoload-file ../app/vendor/autoload.php --dry-run --clear-cache
 *
 * If the application's vendored copy still wins, declare the working tree's rule classes in a shim
 * passed to `--autoload-file`: a class already declared is never autoloaded over.
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
        \Quiote\Rector\Rector\ContextUserToConstructorInjectionRector::class,
        \Quiote\Rector\Rector\ContextGetModelToLocatorRector::class,
        \Quiote\Rector\Rector\ContextGetInstanceToRegistryRector::class,
        \Quiote\Rector\Rector\ContextResidueReporter::class,
    ]);
