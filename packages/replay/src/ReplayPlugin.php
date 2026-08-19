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
use Quiote\Replay\Recording\ActiveEffectLedger;
use Quiote\Replay\Recording\EffectLedgerRegistry;
use Quiote\Replay\Recording\EffectSourceRegistry;
use Quiote\Replay\Recording\RecorderMiddleware;
use Quiote\Replay\Store\CassetteStoreInterface;
use Quiote\Replay\Store\CassetteStoreRegistry;
use Quiote\Replay\Store\FileCassetteStore;
use Quiote\Support\Clock\ClockInterface;
use Quiote\Support\Random\RandomnessInterface;
use RuntimeException;

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
        // Off by default everywhere: replay always runs in ReplayEngine's one mode today (no
        // effect stubbing exists, so replaying really re-performs a request's side effects) --
        // see ReplayEngine's own docblock.
        $registrar->configDefault('replay.allow_live', false);
        // The same rule for an emitted test, which bypasses ReplayEngine so it can run in CI
        // with nothing configured. A safe method needs no opt-in; anything else re-performs a
        // write on every run, so it needs one from whoever knows the test environment is
        // disposable -- see ReplayTestCase.
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

        $registrar->attributedMiddleware(
            RecorderMiddleware::class,
            static function (Context $context): RecorderMiddleware {
                return new RecorderMiddleware(
                    $context,
                    $context->getContainer()->get(CassetteStoreInterface::class),
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
        });
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
