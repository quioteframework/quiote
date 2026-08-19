<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Replay\Cassette\Cassette;
use Throwable;

/**
 * Drives one cassette through the real pipeline and reports drift, per
 * `docs/RECORD_REPLAY_PLAN.md` §7 -- scoped down from that section's own
 * `isolated`/`live` split, though: `isolated` mode (§7.1) means every
 * ledger-backed subsystem is stubbed, and no effect-recorder is wired into
 * a live request yet (§15 item 2's own scoping note). There is nothing to
 * stub, so every replay this engine runs is unavoidably what §7.1 calls
 * `live` -- it really re-performs the request's side effects against
 * whatever the app is actually configured with. A `ReplayMode` enum
 * distinguishing the two is deliberately not added here: it would have
 * nothing to switch on until effect stubbing exists.
 *
 * Because of that, the two safety rules §7.1 states for `live` mode are
 * enforced here rather than deferred with the rest of that section:
 * replay refuses to run at all unless `replay.allow_live` is `true`
 * (default `false`), and refuses a non-idempotent method (anything but
 * GET/HEAD/OPTIONS/PUT/DELETE) without `$force`.
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
