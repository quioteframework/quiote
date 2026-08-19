<?php

declare(strict_types=1);

namespace Quiote\Replay\Console;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Replay\Store\CassetteStoreInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Resolves whichever {@see CassetteStoreInterface} `replay.store` actually
 * names, via the app's own DI container -- the same seam
 * `Quiote\Replay\Recording\RecorderMiddleware`'s factory already resolves it
 * through (`ReplayPlugin::register()`'s `attributedMiddleware` closure).
 *
 * Before this, `cassette:list`/`cassette:show`/`replay` each hardcoded a
 * `new FileCassetteStore(...)` and refused to run at all when `replay.store`
 * named anything else -- correct as far as it went (no other store existed
 * yet), but the console surface was never actually store-agnostic, and a
 * genuinely non-file store (`quioteframework/replay-pdo`) would have had no
 * way to make these commands work at all.
 *
 * Resolved against `core.default_context`, not a per-command `--context`
 * option: `replay.store`/`replay.store.*` are global app config, not
 * per-context, so any bootable context resolves the identical configured
 * store. `ReplayCommand`'s own `--context` option is a separate concern --
 * which context to *replay a request against* -- and is left untouched.
 */
trait ResolvesCassetteStore
{
    private function resolveCassetteStore(SymfonyStyle $io): ?CassetteStoreInterface
    {
        $contextName = Config::getString('core.default_context', 'web');
        try {
            return Context::getInstance($contextName)->getContainer()->get(CassetteStoreInterface::class);
        } catch (Throwable $e) {
            $io->error(sprintf(
                'Could not resolve the configured cassette store ("replay.store" = "%s") via context "%s": %s',
                Config::getString('replay.store', 'file'),
                $contextName,
                $e->getMessage(),
            ));

            return null;
        }
    }
}
