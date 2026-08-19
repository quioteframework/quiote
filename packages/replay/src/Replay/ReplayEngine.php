<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Replay\Cassette\Cassette;
use Throwable;

/**
 * Drives one cassette through the real pipeline and reports drift. An
 * `isolated` replay mode -- every ledger-backed subsystem stubbed from the
 * recorded effects instead of really performed -- is conceivable, but
 * dispatching a replay through the real pipeline has no mechanism today to
 * substitute the `Stubbed*` implementations for a live request's actual
 * PDO/HTTP-client/cache/queue/env services. There is nothing to stub, so
 * every replay this engine runs is unavoidably `live` -- it really
 * re-performs the request's side effects against whatever the app is
 * actually configured with. A `ReplayMode` enum distinguishing the two is
 * deliberately not added here: it would have nothing to switch on until
 * that stub-wiring exists.
 *
 * Because of that, the two safety rules a `live` mode needs are enforced
 * here directly: replay refuses to run at all unless `replay.allow_live` is
 * `true` (default `false`), and refuses anything but a *safe* method without
 * `$force`.
 *
 * Safe, not idempotent. `PUT` and `DELETE` are idempotent -- doing them twice
 * leaves the same state as doing them once -- but that says nothing about
 * whether doing them at all is harmless, and this engine really re-performs
 * the request's side effects. Gating on idempotence let a recorded
 * `DELETE /accounts/42` replay against a live application, and delete account
 * 42, with no prompt.
 */
final class ReplayEngine
{
    /**
     * The HTTP methods defined as safe -- read-only by contract, so re-performing one is not
     * expected to change server state. Anything else needs `$force`.
     *
     * @var list<string>
     */
    public const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * @throws ReplayException if the cassette has no replayable request, `replay.allow_live`
     *         is off, or the method is not a safe one and `$force` is false.
     */
    public function replay(Context $context, Cassette $cassette, bool $force = false): ReplayResult
    {
        $request = RequestReconstructor::fromCassette($cassette);

        if (!Config::getBool('replay.allow_live', false)) {
            throw new ReplayException(
                'Replay only runs in live mode today -- effect stubbing (isolated mode) is not '
                . 'built yet, so replaying really re-performs this request\'s side effects. Set '
                . 'replay.allow_live=true to allow that, and only where doing so is safe.',
            );
        }
        if (!$force && !self::isSafeMethod($request->getMethod())) {
            throw new ReplayException(sprintf(
                'Refusing to replay a %s request without --force: %s is not a safe method, so '
                . 'replaying it will really re-perform its side effects against whatever this '
                . 'context is configured with (no effect stubbing exists yet to make this safe).',
                $request->getMethod(),
                strtoupper($request->getMethod()),
            ));
        }

        $cassetteId = is_string($cassette->meta['id'] ?? null) ? $cassette->meta['id'] : 'unknown';

        try {
            $response = $context->getRequestHandler()->handle($request);
        } catch (Throwable $e) {
            throw new ReplayException(sprintf(
                'Replaying cassette "%s" threw %s: %s',
                $cassetteId,
                $e::class,
                $e->getMessage(),
            ), 0, $e);
        }

        $diagnostics = (new ResponseDiffer())->diff($cassette->response, $response, $cassetteId);

        return new ReplayResult($response, new DriftReport($diagnostics));
    }

    /** Whether $method is one of the safe methods replay will re-perform without `--force`. */
    public static function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::SAFE_METHODS, true);
    }
}
