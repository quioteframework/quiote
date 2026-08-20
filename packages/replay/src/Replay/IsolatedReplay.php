<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Quiote\Cache\CacheManager;
use Quiote\Context;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Recording\EffectSource;
use Quiote\Replay\Recording\EffectSourceRegistry;
use Quiote\Support\Clock\Clock;
use Quiote\Support\Clock\FrozenClock;
use Quiote\Support\Environment\Environment;
use Throwable;

/**
 * Runs a cassette against stubs built from its own recorded effects, so nothing the original request
 * did is performed again.
 *
 * This is the mode the `Stubbed*` classes were written for. They existed, and were unit-tested, and
 * nothing ever constructed them: what was missing was a step that substitutes them for a request's
 * real collaborators, dispatches, and puts everything back. That step is here.
 *
 * Every subsystem is swapped through a seam that already existed for it, which is why this is
 * assembly rather than new machinery -- `Clock::useClock()`,
 * `Randomness::useRandomness()` and `Environment::useEnvironmentReader()` each set a value and
 * return the previous one, `CacheManager::setCache()` swaps the process cache, and the container
 * binds the HTTP client and the queue driver. All of it is undone in a `finally`, including when
 * the dispatch throws, because leaving a stub installed would make every later request in the same
 * process silently replay-shaped.
 *
 * **The clock is frozen at the cassette's `recorded_at`.** Nothing records individual clock reads,
 * so there are no clock effects to match -- but a replay that runs at the recorded instant
 * reproduces every `now()`-dependent branch the original took, which is most of the value a
 * recorded clock would have given. Randomness is deliberately *not* substituted: nothing recorded
 * the values, so any substitute would be inventing them, and inventing input is what an isolated
 * replay exists to avoid.
 *
 * **The database is the one subsystem that cannot always be isolated**, and this refuses to run
 * rather than pretend otherwise. Serving a recorded row needs a seam that sits *in front of* the real
 * execution: Doctrine's DBAL driver middleware is such a decorator, and Propulsion, whose observers
 * only bracket a query that has already run, instead lets the connection itself be replaced.
 * Eloquent's `QueryExecuted` event and Cycle's PSR-3 logger fire after the fact and offer no
 * equivalent, so a replay through them would touch the real database. See {@see IsolatesFromLedger}.
 */
final class IsolatedReplay
{
    /**
     * Dispatches $request against stubs built from $cassette's effects.
     *
     * @throws ReplayException if a registered {@see EffectSource} cannot serve from the ledger, or
     *         if the app is missing a PSR-17 factory pair the HTTP stub needs.
     */
    public function run(Context $context, Cassette $cassette, ServerRequestInterface $request): IsolatedReplayResult
    {
        self::assertEverySourceCanIsolate();

        $ledger = EffectLedger::forReplay($cassette->effects);
        $restore = $this->substitute($context, $cassette, $ledger);

        try {
            $response = $context->getRequestHandler()->handle($request);
        } finally {
            // Unwound in reverse, and in a finally so a throwing dispatch cannot leave a stub
            // installed for the rest of the process.
            foreach (array_reverse($restore) as $undo) {
                $undo();
            }
        }

        return new IsolatedReplayResult(
            $response,
            new DriftReport((new ResponseDiffer())->diff(
                $cassette->response,
                $response,
                is_string($cassette->meta['id'] ?? null) ? $cassette->meta['id'] : 'unknown',
            )),
            $ledger,
        );
    }

    /**
     * Refuses when any registered source only *observes* its driver.
     *
     * Checked before anything is substituted, and refused rather than degraded: an isolated replay
     * that quietly let queries through to the real database would be worse than no isolated mode at
     * all, because the whole reason to reach for it is not wanting to touch production.
     *
     * @throws ReplayException naming each package that cannot isolate.
     */
    private static function assertEverySourceCanIsolate(): void
    {
        $blocking = array_values(array_filter(
            EffectSourceRegistry::all(),
            static fn(EffectSource $source): bool => !$source instanceof IsolatesFromLedger,
        ));
        if ($blocking === []) {
            return;
        }

        throw new ReplayException(sprintf(
            'Cannot replay in isolation: %s observe their queries after they have already run, and offer '
            . 'no way to replace the connection either, so there is no point at which a recorded result '
            . 'could be served instead. Replaying through them would read from -- and write to -- the '
            . 'real database while appearing isolated. Use --live (with replay.allow_live) if that is '
            . 'genuinely what you want, or record the cassette through quioteframework/replay-doctrine '
            . 'or quioteframework/replay-propulsion, both of which can serve from the ledger.',
            implode(', ', array_map(static fn(EffectSource $s): string => $s::class, $blocking)),
        ));
    }

    /**
     * Installs every stub and returns the callables that undo them, in installation order.
     *
     * @return list<\Closure(): void>
     */
    private function substitute(Context $context, Cassette $cassette, EffectLedger $ledger): array
    {
        $undo = [];
        $container = $context->getContainer();

        // Time, frozen at the recorded instant -- see this class's own docblock.
        $recordedAt = \Quiote\Replay\Cassette\RecordedAt::parse(
            is_string($cassette->meta['recorded_at'] ?? null) ? $cassette->meta['recorded_at'] : null,
        );
        if ($recordedAt !== null) {
            $previousClock = Clock::useClock(new FrozenClock($recordedAt->getTimestamp()));
            $undo[] = static function () use ($previousClock): void {
                Clock::useClock($previousClock);
            };
        }

        $previousEnv = Environment::useEnvironmentReader(new StubbedEnvironmentReader($ledger));
        $undo[] = static function () use ($previousEnv): void {
            Environment::useEnvironmentReader($previousEnv);
        };

        $previousCache = CacheManager::getCache();
        CacheManager::setCache(new StubbedCache($ledger), 'replay-isolated');
        $undo[] = static function () use ($previousCache): void {
            CacheManager::setCache($previousCache, 'restored');
        };

        $undo[] = $this->bind($container, ClientInterface::class, $this->httpStub($container, $ledger));

        $queueDriverClass = self::configuredQueueDriverClass();
        if ($queueDriverClass !== null) {
            $undo[] = $this->bind($container, $queueDriverClass, new AssertingQueueDriver($ledger));
        }

        // The database, through the seam the recording decorators already read. Installed last so
        // it is unwound first, before anything else the app might touch on the way out.
        ActiveEffectLedger::set($ledger);
        $undo[] = static function (): void {
            ActiveEffectLedger::set(null);
        };

        // And whatever else each driver package needs doing -- substituting a connection, for a
        // driver whose observers cannot intercept. Every source here is an IsolatesFromLedger,
        // because assertEverySourceCanIsolate() already refused otherwise.
        foreach (EffectSourceRegistry::all() as $source) {
            if (!$source instanceof IsolatesFromLedger) {
                continue;
            }
            $source->beginIsolation($ledger);
            $undo[] = static function () use ($source): void {
                $source->endIsolation();
            };
        }

        return $undo;
    }

    /**
     * Binds $concrete for $id and returns the callable that restores whatever was there.
     *
     * `unset()` rather than re-binding null when nothing was bound before: leaving a stub bound
     * under an id the app had never bound would change what the *next* request resolves.
     *
     * @return \Closure(): void
     */
    private function bind(\Quiote\DI\Container $container, string $id, mixed $concrete): \Closure
    {
        $had = $container->has($id);
        $previous = $had ? $container->tryGet($id) : null;
        $container->set($id, $concrete);

        return static function () use ($container, $id, $had, $previous): void {
            if ($had) {
                $container->set($id, $previous);

                return;
            }
            $container->unset($id);
        };
    }

    /**
     * @throws ReplayException if the app binds no PSR-17 factories, which the HTTP stub needs to
     *         build a response out of a recorded one.
     */
    private function httpStub(\Quiote\DI\Container $container, EffectLedger $ledger): StubbedHttpTransport
    {
        $responseFactory = $container->tryGet(ResponseFactoryInterface::class);
        $streamFactory = $container->tryGet(StreamFactoryInterface::class);

        if (!$responseFactory instanceof ResponseFactoryInterface || !$streamFactory instanceof StreamFactoryInterface) {
            // Falls back to the PSR-17 implementation the framework already depends on rather than
            // failing: an app that never makes an outbound HTTP call has no reason to have bound
            // factories, and refusing to replay it over that would be absurd.
            $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
            $responseFactory = $psr17;
            $streamFactory = $psr17;
        }

        return new StubbedHttpTransport($ledger, $responseFactory, $streamFactory);
    }

    /**
     * The class the configured queue driver alias resolves to, or null when the queue package is not
     * installed.
     *
     * Resolved by class rather than by interface because that is how `Quiote\Queue\QueueManager`
     * asks the container for it -- binding the stub under the interface would leave the real driver
     * to be built for the alias.
     */
    private static function configuredQueueDriverClass(): ?string
    {
        if (!class_exists(\Quiote\Queue\QueueDriverRegistry::class)) {
            return null;
        }

        try {
            return \Quiote\Queue\QueueDriverRegistry::instantiateClassFor(
                \Quiote\Config\Config::getString('queue.driver', 'sync'),
            );
        } catch (Throwable) {
            // An unknown or unregistered alias is the app's own misconfiguration and will surface
            // the moment it tries to queue anything; it is not this class's to report, and it does
            // not stop the rest of the isolation being installed.
            return null;
        }
    }
}
