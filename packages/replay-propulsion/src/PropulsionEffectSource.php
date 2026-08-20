<?php

declare(strict_types=1);

namespace Quiote\Replay\Adapter\Propulsion;

use Propulsion\Propulsion;
use Quiote\Replay\Recording\EffectLedgerRegistry;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Replay\IsolatesFromLedger;
use Throwable;

/**
 * Propulsion's hook into both halves of the replay lifecycle.
 *
 * **Recording** works by correlation id: {@see PropulsionQueryRecorder} is registered once at boot,
 * because `Propulsion::addQueryObserver()` is process-scoped rather than request-scoped, so it needs
 * telling which request's {@see EffectLedger} a given correlation id belongs to.
 *
 * **Isolation** cannot use that seam at all, and substitutes the connection instead. Propulsion's
 * observers are observation-only by contract -- `QueryObserver` states that an observer must not
 * throw, and `queryStarted()` has no way to return a result -- so there is no point at which one
 * could answer a query from the ledger. `Propulsion::setConnection()` replaces what the observers
 * observe, which is both the available seam and the better one: it keeps observation and control
 * apart, where a return channel on `QueryObserver` would let every existing observer lie about a
 * query. See {@see LedgerBackedPropulsionPdo}.
 */
final class PropulsionEffectSource implements IsolatesFromLedger
{
    /**
     * Datasources this source substituted, and the connection it put there.
     *
     * Kept so {@see endIsolation()} can remove exactly what it installed, by identity, rather than
     * guessing at what the map should hold.
     *
     * @var list<array{name: string, connection: LedgerBackedPropulsionPdo}>
     */
    private array $installed = [];

    public function activate(string $correlationId, EffectLedger $ledger): void
    {
        Propulsion::setCorrelationId($correlationId);
        EffectLedgerRegistry::register($correlationId, $ledger);
    }

    public function deactivate(string $correlationId): void
    {
        EffectLedgerRegistry::forget($correlationId);
        Propulsion::setCorrelationId(null);
    }

    /**
     * Points every datasource at a connection that answers from $ledger.
     *
     * Both read and write modes, per datasource: a replayed request that reads through the slave and
     * writes through the master would otherwise have half its queries isolated and half of them
     * reaching a real server, which is worse than either.
     *
     * The datasource list comes from `Propulsion::getDatabaseMapNames()`, plus the default -- a map
     * is registered for every datasource the ORM's generated code touches, which is the set that can
     * produce a query. A datasource nothing has touched yet has no map, and also no query to serve.
     */
    #[\Override]
    public function beginIsolation(EffectLedger $ledger): void
    {
        foreach (self::datasourceNames() as $name) {
            $connection = new LedgerBackedPropulsionPdo($ledger);
            // Both modes get the same instance: the ledger is the single source of answers, and a
            // second object would only split the transaction bookkeeping in two.
            Propulsion::setConnection($name, $connection, Propulsion::CONNECTION_WRITE);
            Propulsion::setConnection($name, $connection, Propulsion::CONNECTION_READ);
            $this->installed[] = ['name' => $name, 'connection' => $connection];
        }
    }

    /**
     * Removes what {@see beginIsolation()} installed, so the next real query opens a real connection.
     *
     * `discardConnection()` rather than putting the previous connection back, because there is no way
     * to have captured it: `Propulsion::getConnection()` *opens* one when the map is empty, so
     * reading the pre-replay state would have created the very connection an isolated replay exists
     * to avoid. Discarding leaves the map empty and Propulsion reopens from configuration on next
     * use -- one extra connect in a long-lived process, and nothing wrong in a CLI one, which is
     * where a replay usually runs. A non-opening peek keyed by datasource and mode would make this
     * exact; Propulsion has none today.
     *
     * Never throws: it runs in `IsolatedReplay`'s `finally`, where a throw would replace whatever the
     * replay itself was reporting and leave a stub connection installed for the rest of the process.
     */
    #[\Override]
    public function endIsolation(): void
    {
        foreach ($this->installed as $entry) {
            try {
                Propulsion::discardConnection($entry['connection']);
            } catch (Throwable) {
                // Nothing useful to do and nothing safe to throw. The connection is a stub either
                // way; the worst case is Propulsion holding one that answers from a finished
                // ledger, which the next discardConnection() or closeConnections() clears.
            }
        }
        $this->installed = [];
    }

    /**
     * @return list<string> Every datasource that could produce a query, deduplicated.
     */
    private static function datasourceNames(): array
    {
        $names = Propulsion::getDatabaseMapNames();

        try {
            $default = Propulsion::getDefaultDB();
            if ($default !== '') {
                $names[] = $default;
            }
        } catch (Throwable) {
            // No configuration loaded, so no default to add. The maps above are still the set that
            // matters, and an empty result simply means there is nothing to substitute.
        }

        return array_values(array_unique($names));
    }
}
