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
 * `true` (default `false`), and refuses a non-idempotent method (anything
 * but GET/HEAD/OPTIONS/PUT/DELETE) without `$force`.
 */
final class ReplayEngine
{
    /** @var list<string> */
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    /**
     * @throws ReplayException if the cassette has no replayable request, `replay.allow_live`
     *         is off, or the method is non-idempotent and `$force` is false.
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
        if (!$force && !in_array(strtoupper($request->getMethod()), self::IDEMPOTENT_METHODS, true)) {
            throw new ReplayException(sprintf(
                'Refusing to replay a %s request without --force: it will really re-perform '
                . 'its side effects (no effect stubbing exists yet to make this safe).',
                $request->getMethod(),
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
}
