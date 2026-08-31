<?php

declare(strict_types=1);

namespace Quiote\Replay\Store;

use Quiote\Exception\StorageException;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Throwable;

/**
 * Stands in for a store that could not be constructed -- a `replay.store` alias whose plugin was
 * never registered, a provider missing a credential, an unreachable endpoint at boot.
 *
 * Recording is a diagnostic facility, so a misconfigured store must not be able to take a request
 * down: {@see \Quiote\Replay\ReplayPlugin} substitutes this rather than letting the construction
 * failure escape the middleware factory, where it would abort pipeline construction on *every*
 * request, before {@see \Quiote\Replay\Recording\RecorderMiddleware}'s own `put()` guard -- the one
 * place that reports a storage failure -- could ever run. The symptom of that arrangement was the
 * worst possible one: no cassette and no log line, indistinguishable from recording being off.
 *
 * `put()` throws rather than silently discarding, so each recording attempt is reported once by
 * that guard, naming the original construction failure. Reads answer empty: nothing was ever
 * written through this store, and that is not an error worth throwing over.
 */
final class UnavailableCassetteStore implements CassetteStoreInterface
{
    public function __construct(private readonly Throwable $cause)
    {
    }

    public function put(CassetteId $id, Cassette $cassette): void
    {
        throw new StorageException(
            sprintf('the cassette store could not be constructed: %s', $this->cause->getMessage()),
            previous: $this->cause,
        );
    }

    public function get(CassetteId $id): ?Cassette
    {
        return null;
    }

    public function has(CassetteId $id): bool
    {
        return false;
    }

    public function delete(CassetteId $id): void
    {
    }
}
