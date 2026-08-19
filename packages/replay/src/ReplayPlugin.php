<?php

declare(strict_types=1);

namespace Quiote\Replay;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Plugin\Attribute\Plugin as PluginAttribute;
use Quiote\Plugin\PluginInterface;
use Quiote\Plugin\PluginRegistrar;
use Quiote\Replay\Console\CassetteListCommand;
use Quiote\Replay\Console\CassetteShowCommand;
use Quiote\Replay\Console\ReplayCommand;
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
        $registrar->configDefault('replay.write', 'sync_on_error');
        $registrar->configDefault('replay.max_bytes', 2_097_152);
        $registrar->configDefault('replay.max_effects', 2000);
        $registrar->configDefault('replay.capture_body', true);
        $registrar->configDefault('replay.capture_session', true);
        $registrar->configDefault('replay.capture_log', false);
        $registrar->configDefault('replay.redact.headers', ['authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'x-api-key']);
        $registrar->configDefault('replay.redact.params', ['password', 'password_confirm', 'token', 'secret', 'card', 'cvv', 'ssn']);
        $registrar->configDefault('replay.redact.session', ['_csrf', 'auth.token']);
        $registrar->configDefault('replay.redact.mode', 'drop');
        // Off by default everywhere: replay always runs in ReplayEngine's one mode today (no
        // effect stubbing exists, so replaying really re-performs a request's side effects) --
        // see ReplayEngine's own docblock.
        $registrar->configDefault('replay.allow_live', false);

        // Singleton: the file store checks/creates its directory (and its permissions) once at
        // construction, and there is no reason to repeat that per request.
        $registrar->service(CassetteStoreInterface::class, static fn(): CassetteStoreInterface => self::makeStore(), Container::SCOPE_SINGLETON);

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
        $registrar->command(ReplayCommand::class);

        $registrar->stateReset('quioteframework/replay', static function (): void {
            CassetteStoreRegistry::reset();
            EffectLedgerRegistry::reset();
            EffectSourceRegistry::reset();
        });
    }

    private static function makeStore(): CassetteStoreInterface
    {
        $alias = Config::getString('replay.store', 'file');
        $class = CassetteStoreRegistry::instantiateClassFor($alias);

        if ($class === FileCassetteStore::class) {
            return new FileCassetteStore(Config::getString('replay.store.path', 'var/cassettes'));
        }

        // A registered non-file store must supply its own factory (its plugin's job to
        // register a service for CassetteStoreInterface ahead of this one, per the
        // set-if-absent contract PluginRegistrar::service() documents), since this class knows
        // how to construct only the built-in file store.
        throw new RuntimeException(sprintf(
            'Cassette store "%s" (class "%s") has no constructor known to %s; its own plugin must register a %s service instead of relying on this default factory.',
            $alias,
            $class,
            self::class,
            CassetteStoreInterface::class,
        ));
    }
}
