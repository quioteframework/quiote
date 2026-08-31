<?php

declare(strict_types=1);

namespace Quiote\Replay;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Replay\Console\CassetteFetchCommand;
use Quiote\Replay\Console\CassetteListCommand;
use Quiote\Replay\Console\CassettePruneCommand;
use Quiote\Replay\Console\CassetteShowCommand;
use Quiote\Replay\Console\ReplayCommand;
use Quiote\Replay\Index\CassetteIndexRegistry;
use Quiote\Logging\Log;
use Quiote\Logging\LogRegistry;
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Recording\EffectLedgerRegistry;
use Quiote\Replay\Recording\EffectSourceRegistry;
use Quiote\Replay\Recording\RecorderMiddleware;
use Quiote\Replay\Recording\RecordingLogBuffer;
use Quiote\Replay\Recording\RecordingLogSink;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Replay\Store\CassetteStoreRegistry;
use Quiote\Replay\Store\FileCassetteStore;
use Quiote\Replay\Store\UnavailableCassetteStore;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Random\RandomnessInterface;
use RuntimeException;
use Throwable;

/**
 * Registers the replay configuration defaults, the cassette store, the
 * recorder middleware and the cassette console commands, through the
 * generic plugin seam -- mirroring {@see \Quiote\Security\RateLimit\RateLimitPlugin}'s
 * shape.
 *
 * `replay.enabled` defaults to false and `replay.record` to `never`, so
 * installing the package alone changes nothing: {@see RecorderMiddleware}
 * checks both at the top of `process()` and passes straight through when
 * either says not to record, the same pattern `ratelimit.http.enabled` and
 * `RateLimitMiddleware` already follow -- config defaults, the store
 * service and the middleware are still registered unconditionally, and the
 * *behaviour* is what the flag gates.
 */
#[PluginAttribute(name: 'quioteframework/replay')]
final class ReplayPlugin implements PluginInterface
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->configDefault('replay.enabled', false);
        $registrar->configDefault('replay.record', 'never');
        $registrar->configDefault('replay.sample_rate', 0.0);
        $registrar->configDefault('replay.trigger_header', 'X-Quiote-Record');
        $registrar->configDefault('replay.store', 'file');
        $registrar->configDefault('replay.store.path', 'var/cassettes');
        // Where cassette:fetch/replay --save cache a cassette resolved via the store or an
        // index, so a repeat lookup for the same id needs no network -- deliberately separate
        // from replay.store.path, which is the file store's own storage location.
        $registrar->configDefault('replay.local_path', 'var/cassettes');
        $registrar->configDefault('replay.tests_path', 'tests/Replay');
        // Consumed by CassettePruneCommand's default --older-than when the store is file/pdo;
        // meaningless on Azure, which prunes via a blob lifecycle rule instead.
        $registrar->configDefault('replay.retention_days', 14);
        $registrar->configDefault('replay.max_bytes', 2_097_152);
        $registrar->configDefault('replay.max_effects', 2000);
        $registrar->configDefault('replay.max_log_entries', 500);
        $registrar->configDefault('replay.capture_body', true);
        $registrar->configDefault('replay.capture_session', true);
        $registrar->configDefault('replay.redact.headers', ['authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'x-api-key']);
        $registrar->configDefault('replay.redact.params', ['password', 'password_confirm', 'token', 'secret', 'card', 'cvv', 'ssn']);
        $registrar->configDefault('replay.redact.session', ['_csrf', 'auth.token']);
        // Matched as case-insensitive substrings of an environment variable's name, not as exact
        // names: env vars are named per deployment (APP_DB_PASSWORD, STRIPE_SECRET_KEY), so an
        // exact-match denylist would have to enumerate every name in every app to work at all.
        $registrar->configDefault('replay.redact.env', [
            'password', 'passwd', 'secret', 'token', 'key', 'credential', 'private',
            'auth', 'dsn', 'connection_string', 'connectionstring', 'salt', 'cert',
        ]);
        $registrar->configDefault('replay.redact.mode', 'drop');
        // Salts replay.redact.mode=hash. Empty means unsalted, which is not a redaction for a
        // low-entropy value: the shipped redact.params default denies cvv/ssn/card, and an
        // unsalted digest of a three-digit number falls to a thousand guesses. Set it where hash
        // mode is used -- see Redactor::apply().
        $registrar->configDefault('replay.redact.hash_salt', '');
        // Off by default: `quiote replay` replays in isolation, which performs nothing and needs no
        // configuration. This gates `--live`, which dispatches against the real collaborators and so
        // really re-performs the request's side effects -- see ReplayEngine.
        $registrar->configDefault('replay.allow_live', false);
        // Off by default: an emitted test replays in isolation, so a recorded POST or DELETE is safe
        // to re-run on every CI build. Turning this on opts a suite into a live dispatch with real
        // reads and writes, and is only safe where the environment is disposable -- see
        // ReplayTestCase.
        $registrar->configDefault('replay.tests_allow_live', false);

        // Singleton: the file store checks/creates its directory (and its permissions) once at
        // construction, and there is no reason to repeat that per request.
        //
        // The one binding for every store, not just the file one. A store package registers a
        // factory against its alias in CassetteStoreRegistry and this resolves whichever alias
        // `replay.store` names -- so `replay-azure` and `replay-pdo` no longer have to load before
        // this plugin to claim the binding, and a package installed but not selected is never
        // constructed. See CassetteStoreRegistry::$factories for what the previous arrangement did.
        $registrar->service(
            CassetteStoreInterface::class,
            static fn(Container $container): CassetteStoreInterface => self::makeStore($container),
            Container::SCOPE_SINGLETON,
        );

        // Feeds RecorderMiddleware's `log` cassette section; see RecordingLogSink's own
        // docblock for why this is a plain boot-time sink registration rather than
        // something wired per request. Guarded by a scan of the current sink list, not a
        // static flag: `register()` runs again every time a test (or a re-booted worker)
        // boots this plugin, and `LogRegistry::sinks()` has no de-duplication of its own --
        // a flag that stays true across a `Log::reset()` would leave this sink silently
        // missing forever after, while re-adding unconditionally would duplicate every
        // buffered log line once per boot.
        if (!self::hasRecordingLogSink()) {
            Log::addSink(new RecordingLogSink());
        }

        $registrar->attributedMiddleware(
            RecorderMiddleware::class,
            static function (Context $context): RecorderMiddleware {
                return new RecorderMiddleware(
                    $context,
                    self::resolveStore($context->getContainer()),
                    $context->getContainer()->get(ClockInterface::class),
                    $context->getContainer()->get(RandomnessInterface::class),
                );
            },
        );

        $registrar->command(CassetteListCommand::class);
        $registrar->command(CassetteShowCommand::class);
        $registrar->command(CassettePruneCommand::class);
        $registrar->command(CassetteFetchCommand::class);
        $registrar->command(ReplayCommand::class);

        $registrar->stateReset('quioteframework/replay', static function (): void {
            CassetteStoreRegistry::reset();
            CassetteIndexRegistry::reset();
            EffectLedgerRegistry::reset();
            EffectSourceRegistry::reset();
            ActiveEffectLedger::reset();
            RecordingLogBuffer::reset();
        });
    }

    private static function hasRecordingLogSink(): bool
    {
        foreach (LogRegistry::sinks() as $sink) {
            if ($sink instanceof RecordingLogSink) {
                return true;
            }
        }

        return false;
    }

    /**
     * This runs inside the middleware *factory*, which the pipeline invokes with no guard of its
     * own, so an exception here aborts pipeline construction for every request -- a misconfigured
     * recorder taking down the application it exists to diagnose. Worse, it happens before
     * {@see RecorderMiddleware}'s `put()` guard, the only place that reports a storage failure, so
     * the failure mode is a request that dies with no cassette and no log line at all.
     *
     * Reported once here, at boot, with the alias that failed named; the substitute store then
     * reports again on each recording attempt through that same `put()` guard. The console
     * commands keep resolving `CassetteStoreInterface` from the container directly and still fail
     * hard -- a developer running `cassette:list` against a broken store wants the exception.
     */
    private static function resolveStore(Container $container): CassetteStoreInterface
    {
        try {
            return $container->get(CassetteStoreInterface::class);
        } catch (Throwable $e) {
            Log::create(self::class)->error(sprintf(
                'Cassette store "%s" could not be built, so no cassettes will be recorded: %s',
                Config::getString('replay.store', 'file'),
                $e->getMessage(),
            ));

            return new UnavailableCassetteStore($e);
        }
    }

    private static function makeStore(Container $container): CassetteStoreInterface
    {
        $alias = Config::getString('replay.store', 'file');
        $class = CassetteStoreRegistry::instantiateClassFor($alias);

        $factory = CassetteStoreRegistry::factoryFor($alias);
        if ($factory !== null) {
            return $factory($container);
        }

        if ($class === FileCassetteStore::class) {
            return new FileCassetteStore(Config::getString('replay.store.path', 'var/cassettes'));
        }

        // A registered alias with no factory: its package named itself in the registry but left no
        // way to build the store. That is a packaging mistake rather than a user misconfiguration,
        // so it names the class and what is missing.
        throw new RuntimeException(sprintf(
            'Cassette store "%s" (class "%s") registered no factory with %s, so %s cannot build it. '
            . 'Its own plugin must pass a factory to CassetteStoreRegistry::register().',
            $alias,
            $class,
            CassetteStoreRegistry::class,
            self::class,
        ));
    }
}
