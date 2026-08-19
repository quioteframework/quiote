<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Replay\Cassette\Cassette;
use Throwable;

/**
 * Drives one cassette through the pipeline and reports drift, in one of two modes.
 *
 * {@see ReplayMode::Isolated} is the default and needs no configuration: every ledger-backed
 * subsystem is answered from the cassette's own recorded effects and nothing is performed, so the
 * replay can run anywhere -- which is the point of having recorded the request in the first place.
 * It also reports more than a live replay can: in isolation every effect goes through the ledger, so
 * "the code asked for something the recording does not contain" and "the recording contains
 * something the code no longer asks for" both become answerable rather than invisible. See
 * {@see IsolatedReplay}.
 *
 * {@see ReplayMode::Live} really re-performs the request's side effects against whatever the context
 * is configured with. It exists for the one thing isolation cannot do -- confirm a fix works against
 * real collaborators -- and carries the two safety rules that needs:
 *
 *  - it refuses unless `replay.allow_live` is `true` (default `false`);
 *  - it refuses anything but a *safe* method without `$force`.
 *
 * Safe, not idempotent. `PUT` and `DELETE` are idempotent -- doing them twice leaves the same state
 * as doing them once -- but that says nothing about whether doing them at all is harmless. Gating on
 * idempotence let a recorded `DELETE /accounts/42` replay against a live application and delete
 * account 42, with no prompt.
 */
final class ReplayEngine
{
    /**
     * The HTTP methods defined as safe -- read-only by contract, so re-performing one is not
     * expected to change server state. Anything else needs `$force` in {@see ReplayMode::Live}.
     *
     * @var list<string>
     */
    public const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * @param ReplayMode $mode Isolated by default; {@see ReplayMode::Live} re-performs for real.
     * @throws ReplayException if the cassette has no replayable request; if isolation is impossible
     *         for the registered effect sources; or, in live mode, if `replay.allow_live` is off or
     *         the method is not a safe one and `$force` is false.
     */
    public function replay(
        Context $context,
        Cassette $cassette,
        bool $force = false,
        ReplayMode $mode = ReplayMode::Isolated,
    ): ReplayResult {
        $request = RequestReconstructor::fromCassette($cassette);
        $cassetteId = is_string($cassette->meta['id'] ?? null) ? $cassette->meta['id'] : 'unknown';

        if ($mode === ReplayMode::Isolated) {
            try {
                $isolated = (new IsolatedReplay())->run($context, $cassette, $request);
            } catch (ReplayException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new ReplayException(sprintf(
                    'Replaying cassette "%s" in isolation threw %s: %s',
                    $cassetteId,
                    $e::class,
                    $e->getMessage(),
                ), 0, $e);
            }

            // Effect drift folded in alongside the response diff, so a caller reports one list. Only
            // isolated mode can produce it: a live replay's effects go to real collaborators, not
            // through a ledger that could notice one missing.
            return new ReplayResult($isolated->response, $isolated->allDiagnostics($cassetteId), $isolated->ledger);
        }

        if (!Config::getBool('replay.allow_live', false)) {
            throw new ReplayException(
                'Live replay really re-performs this request\'s side effects against whatever this context is '
                . 'configured with. Set replay.allow_live=true to allow that, and only where doing so is safe '
                . '-- or drop --live and replay in isolation instead, which performs nothing and needs no '
                . 'configuration at all.',
            );
        }
        if (!$force && !self::isSafeMethod($request->getMethod())) {
            throw new ReplayException(sprintf(
                'Refusing to replay a %s request live without --force: %s is not a safe method, so replaying '
                . 'it will really re-perform its side effects against whatever this context is configured '
                . 'with.',
                $request->getMethod(),
                strtoupper($request->getMethod()),
            ));
        }

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

        return new ReplayResult(
            $response,
            new DriftReport((new ResponseDiffer())->diff($cassette->response, $response, $cassetteId)),
        );
    }

    /** Whether $method is one of the safe methods a live replay will re-perform without `--force`. */
    public static function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::SAFE_METHODS, true);
    }
}
