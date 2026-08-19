<?php

declare(strict_types=1);

namespace Quiote\Replay\Console;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\CassetteIndexChain;
use Quiote\Replay\Index\CassetteIndexException;
use Quiote\Replay\Index\CassetteIndexRegistry;
use Quiote\Replay\Index\IndexHints;
use Quiote\Replay\Store\FileCassetteStore;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Resolves a bare cassette id the way `quiote replay`/`quiote cassette:fetch` both promise: the
 * local cache first (no network), then whichever store `replay.store` names, then -- only if
 * still missing -- the contributed {@see \Quiote\Replay\Index\CassetteIndexInterface} chain using
 * whatever `--key`/`--date`/`--hour` hints were given. A cassette resolved via the store or an
 * index is written into the local cache before returning, so a repeat lookup for the same id needs
 * no network at all.
 *
 * The local cache is deliberately a *different* directory concern from `replay.store.path` (the
 * file store's own path, when `replay.store = file`): `replay.local_path` exists specifically so
 * a remote-store deployment (`replay.store = azure-blob`) still gets a fast, offline-capable local
 * copy once fetched, per `replay.local_path`'s own config default.
 */
trait ResolvesCassetteViaIndexes
{
    use ResolvesCassetteStore;

    /**
     * @return array{cassette: Cassette, source: string, cached_path: ?string}|null null only after
     *         already reporting the failure to $io.
     */
    private function fetchCassette(SymfonyStyle $io, CassetteId $id, IndexHints $hints, ?string $localPathOverride = null): ?array
    {
        try {
            $localStore = new FileCassetteStore($this->localCacheDirectory($localPathOverride));
        } catch (Throwable $e) {
            $io->error(sprintf('Could not open the local cassette cache: %s', $e->getMessage()));

            return null;
        }

        try {
            $cassette = $localStore->get($id);
        } catch (Throwable $e) {
            $io->error(sprintf('Could not read the local cassette cache: %s', $e->getMessage()));

            return null;
        }
        if ($cassette !== null) {
            return ['cassette' => $cassette, 'source' => 'the local cache', 'cached_path' => null];
        }

        // An exact key is what the option documents itself as -- "bypassing id-based resolution" --
        // so it goes straight to the index chain. Trying the configured store first meant the
        // object store walked its whole lookback window with a head() per hour before reaching a
        // key the caller had already supplied exactly.
        if ($hints->key !== null && $hints->key !== '') {
            $resolved = $this->resolveViaIndexChain($io, $id, $hints);
            if ($resolved === null) {
                return null;
            }
            [$cassette, $source] = $resolved;

            return $this->cached($io, $localStore, $id, $cassette, $source, $localPathOverride);
        }

        $configuredStore = $this->resolveCassetteStore($io);
        if ($configuredStore === null) {
            return null;
        }
        try {
            $cassette = $configuredStore->get($id);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return null;
        }
        $source = sprintf('the configured store ("replay.store" = "%s")', Config::getString('replay.store', 'file'));

        if ($cassette === null) {
            $resolved = $this->resolveViaIndexChain($io, $id, $hints);
            if ($resolved === null) {
                return null;
            }
            [$cassette, $source] = $resolved;
        }

        return $this->cached($io, $localStore, $id, $cassette, $source, $localPathOverride);
    }

    /**
     * Writes the resolved cassette into the local cache and describes where it landed. A cache
     * write that fails is a warning, not a failure: the cassette is in hand either way.
     *
     * @return array{cassette: Cassette, source: string, cached_path: ?string}
     */
    private function cached(
        SymfonyStyle $io,
        FileCassetteStore $localStore,
        CassetteId $id,
        Cassette $cassette,
        string $source,
        ?string $localPathOverride,
    ): array {
        try {
            $localStore->put($id, $cassette);
        } catch (Throwable $e) {
            $io->warning(sprintf('Fetched the cassette but could not cache it locally: %s', $e->getMessage()));

            return ['cassette' => $cassette, 'source' => $source, 'cached_path' => null];
        }

        return ['cassette' => $cassette, 'source' => $source, 'cached_path' => rtrim($this->localCacheDirectory($localPathOverride), '/\\') . '/' . $id->slug . '.qcast'];
    }

    /** @return array{0: Cassette, 1: string}|null null only after already reporting the failure to $io. */
    private function resolveViaIndexChain(SymfonyStyle $io, CassetteId $id, IndexHints $hints): ?array
    {
        try {
            $contextName = Config::getString('core.default_context', 'web');
            $indexes = CassetteIndexRegistry::build(Context::getInstance($contextName)->getContainer());
        } catch (Throwable $e) {
            $io->error(sprintf('Could not build the cassette index chain: %s', $e->getMessage()));

            return null;
        }

        try {
            return [CassetteIndexChain::resolve($indexes, $id, $hints), 'cassette index resolution'];
        } catch (CassetteIndexException $e) {
            $io->error($e->getMessage());

            return null;
        }
    }

    private function localCacheDirectory(?string $override): string
    {
        return $override !== null && $override !== ''
            ? $override
            : Config::getString('replay.local_path', 'var/cassettes');
    }

    private static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
