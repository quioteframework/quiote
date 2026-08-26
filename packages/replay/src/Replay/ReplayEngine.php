<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Session\SessionManager;
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

    /** A session id must look like one {@see SessionManager} would itself have generated. */
    private const SESSION_ID_PATTERN = '/^[A-Za-z0-9_\-]{16,64}$/';

    /**
     * @param ReplayMode $mode Isolated by default; {@see ReplayMode::Live} re-performs for real.
     * @param ?string $uriOverride Replace the cassette's recorded URI (path and/or query) before
     *        replaying, e.g. because the recorded path segment (`/orders/23940239`) does not exist
     *        in this environment. Applied before dispatch either mode, so the app re-routes against
     *        it exactly as it would a real request -- the cassette's `resolved.route_params` are
     *        never consulted here at all.
     * @param ?string $asSessionId Replay authenticated as the *real, live* session with this id
     *        instead of whatever cookie the cassette carried. There is deliberately no way to
     *        fabricate session content from the cassette itself: {@see RecorderMiddleware} only
     *        ever captures a session's id and whether it existed, never its data, so the only
     *        honest way to replay an authenticated request is to point it at a session id that
     *        genuinely exists right now in this context's own session store (your own browser
     *        session, or a service/test account's) -- see {@see applySessionOverride()}. This
     *        performs a real lookup against the real session backend even under
     *        {@see ReplayMode::Isolated}, since session storage is not one of the subsystems
     *        {@see IsolatedReplay} isolates.
     * @throws ReplayException if the cassette has no replayable request; if isolation is impossible
     *         for the registered effect sources; if `$asSessionId` is malformed or this context has
     *         no session configured; or, in live mode, if `replay.allow_live` is off or the method
     *         is not a safe one and `$force` is false.
     */
    public function replay(
        Context $context,
        Cassette $cassette,
        bool $force = false,
        ReplayMode $mode = ReplayMode::Isolated,
        ?string $uriOverride = null,
        ?string $asSessionId = null,
    ): ReplayResult {
        $request = RequestReconstructor::fromCassette($cassette);
        if ($uriOverride !== null) {
            $request = self::applyUriOverride($request, $uriOverride);
        }
        if ($asSessionId !== null) {
            $request = self::applySessionOverride($context, $request, $asSessionId);
        }
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

    /**
     * @throws ReplayException if $uriOverride is not a URI PSR-7 will accept.
     */
    private static function applyUriOverride(ServerRequestInterface $request, string $uriOverride): ServerRequestInterface
    {
        try {
            $uri = (new Psr17Factory())->createUri($uriOverride);
        } catch (\InvalidArgumentException $e) {
            throw new ReplayException(sprintf(
                '--uri "%s" is not a URI PSR-7 will accept: %s',
                $uriOverride,
                $e->getMessage(),
            ), 0, $e);
        }

        return $request->withUri($uri);
    }

    /**
     * Swaps in $sessionId as the cookie value for whichever cookie name this context's
     * {@see SessionManager} is configured to read, so the *real* {@see SessionManager} bound to
     * this context loads the *real* session recorded under that id -- see this method's own
     * {@see replay()} docblock for why nothing here fabricates session content instead.
     *
     * @throws ReplayException if $sessionId is not shaped like a session id {@see SessionManager}
     *         would itself generate, or this context has no "session" factory slot declared at all.
     */
    private static function applySessionOverride(Context $context, ServerRequestInterface $request, string $sessionId): ServerRequestInterface
    {
        if (preg_match(self::SESSION_ID_PATTERN, $sessionId) !== 1) {
            throw new ReplayException(sprintf(
                '--as-session "%s" is not shaped like a session id (16-64 chars of A-Za-z0-9_-). '
                . 'SessionManager would treat anything else as absent and replay anonymously, silently '
                . 'defeating the point of passing it.',
                $sessionId,
            ));
        }

        $manager = $context->getContainer()->tryGet(SessionManager::class);
        if (!$manager instanceof SessionManager) {
            throw new ReplayException(
                '--as-session was given but this context declares no "session" factory slot, so '
                . 'there is no SessionManager to resolve a session id against.',
            );
        }

        $cookies = $request->getCookieParams();
        $cookies[$manager->getCookieName()] = $sessionId;

        return $request->withCookieParams($cookies);
    }
}
