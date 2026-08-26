<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
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
     * @param list<string> $queryOverrides Each entry is one `key=value` pair (or `key[]=value` to
     *        append to an array) merged onto the URI's query string, overriding a matching
     *        recorded key -- e.g. a recorded filter id this environment doesn't have. Parsed and
     *        rebuilt together via `parse_str()`/`http_build_query()`, so PHP's own bracket-array
     *        syntax works exactly as it does in a real query string.
     * @param list<string> $bodyOverrides Same `key=value`/`key[]=value` shape as $queryOverrides,
     *        merged onto the request body instead -- e.g. a recorded
     *        `BusinessUnits[]=162345&BusinessUnits[]=2415134` this environment doesn't have. Which
     *        merge strategy runs depends on the request's own `Content-Type`: a form-urlencoded
     *        body merges the same way $queryOverrides does; a JSON body decodes, assigns each
     *        `key`/`key[]` at the top level (the value is JSON-decoded when it parses as JSON, so
     *        `--body count=3` and `--body active=true` set a number/bool rather than a string),
     *        and re-encodes. See {@see applyBodyOverrides()}.
     * @param bool $enforceCsrf CSRF validation (`quioteframework/csrf`) is disabled for the
     *        duration of dispatch unless this is true. A CSRF token is deliberately validated
     *        against *current* server-side state -- that is the entire point of it existing -- so a
     *        recorded token is exactly as replayable as the anti-replay mechanism it is designed to
     *        be: it works only by coincidence (the same session, not yet rotated), never by design.
     *        Replay exists to reproduce what the application's own logic did, not to re-prove the
     *        request still holds a valid anti-forgery token, so this is off by default; set it when
     *        the CSRF layer itself is what you are trying to reproduce. See {@see withCsrfPolicy()}.
     * @throws ReplayException if the cassette has no replayable request; if isolation is impossible
     *         for the registered effect sources; if `$asSessionId` is malformed or this context has
     *         no session configured; if an override is not in `key=value` form; or, in live mode, if
     *         `replay.allow_live` is off or the method is not a safe one and `$force` is false.
     */
    public function replay(
        Context $context,
        Cassette $cassette,
        bool $force = false,
        ReplayMode $mode = ReplayMode::Isolated,
        ?string $uriOverride = null,
        ?string $asSessionId = null,
        array $queryOverrides = [],
        array $bodyOverrides = [],
        bool $enforceCsrf = false,
    ): ReplayResult {
        $request = RequestReconstructor::fromCassette($cassette);
        if ($uriOverride !== null) {
            $request = self::applyUriOverride($request, $uriOverride);
        }
        if ($queryOverrides !== []) {
            $request = self::applyQueryOverrides($request, $queryOverrides);
        }
        if ($bodyOverrides !== []) {
            $request = self::applyBodyOverrides($request, $bodyOverrides);
        }
        if ($asSessionId !== null) {
            $request = self::applySessionOverride($context, $request, $asSessionId);
        }
        $cassetteId = is_string($cassette->meta['id'] ?? null) ? $cassette->meta['id'] : 'unknown';

        if ($mode === ReplayMode::Isolated) {
            try {
                $isolated = self::withCsrfPolicy(
                    $enforceCsrf,
                    static fn(): IsolatedReplayResult => (new IsolatedReplay())->run($context, $cassette, $request),
                );
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
            $response = self::withCsrfPolicy(
                $enforceCsrf,
                static fn(): \Psr\Http\Message\ResponseInterface => $context->getRequestHandler()->handle($request),
            );
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
     * Runs $dispatch with `core.csrf.enabled` forced off, restoring whatever it was afterward --
     * unless $enforceCsrf, in which case $dispatch just runs as-is.
     *
     * A config toggle rather than anything naming `quioteframework/csrf`'s own classes: this
     * package carries no dependency on it (the same reason {@see IsolatedReplay} names no ORM/DB
     * driver), and `CsrfValidationMiddleware` already reads this exact key on every request, so
     * there is nothing else to wire. Harmless when the csrf package is not installed at all --
     * nothing ever reads the key.
     *
     * @template T
     * @param callable(): T $dispatch
     * @return T
     */
    private static function withCsrfPolicy(bool $enforceCsrf, callable $dispatch): mixed
    {
        if ($enforceCsrf) {
            return $dispatch();
        }

        $hadValue = Config::has('core.csrf.enabled');
        $previous = $hadValue ? Config::getBool('core.csrf.enabled') : null;
        Config::set('core.csrf.enabled', false, true, false);
        try {
            return $dispatch();
        } finally {
            if ($hadValue) {
                Config::set('core.csrf.enabled', $previous, true, false);
            } else {
                Config::remove('core.csrf.enabled');
            }
        }
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
     * Merges $overrides onto the URI's existing query string via `parse_str()`/`http_build_query()`
     * -- see {@see replay()}'s own docblock for the `key=value`/`key[]=value` shape this accepts.
     *
     * @param list<string> $overrides
     */
    private static function applyQueryOverrides(ServerRequestInterface $request, array $overrides): ServerRequestInterface
    {
        $uri = $request->getUri();
        parse_str($uri->getQuery(), $existing);
        $merged = self::mergeFormEncoded($existing, $overrides);

        return $request->withUri($uri->withQuery(http_build_query($merged, '', '&', PHP_QUERY_RFC3986)));
    }

    /**
     * Merges $overrides onto the request body -- see {@see replay()}'s own docblock for the two
     * merge strategies this picks between based on `Content-Type`.
     *
     * @param list<string> $overrides
     * @throws ReplayException if an override is not in `key=value` form.
     */
    private static function applyBodyOverrides(ServerRequestInterface $request, array $overrides): ServerRequestInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        $raw = (string)$request->getBody();

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw !== '' ? $raw : '{}', true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            // Tracks which `key[]` base keys this batch has already reset, so the first
            // `key[]=...` in a batch replaces whatever array was recorded (matching the form path's
            // array_replace() below, which replaces the whole key rather than appending to it) and
            // every subsequent one in the same batch accumulates onto it.
            $resetBases = [];
            foreach ($overrides as $override) {
                [$key, $value] = self::splitOverride($override);
                self::assignJsonOverride($decoded, $key, $value, $resetBases);
            }
            $newBody = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        } else {
            parse_str($raw, $existing);
            $newBody = http_build_query(self::mergeFormEncoded($existing, $overrides), '', '&', PHP_QUERY_RFC3986);
        }

        return $request->withBody((new Psr17Factory())->createStream($newBody));
    }

    /**
     * @param array<array-key, mixed> $existing Already parsed (e.g. via `parse_str()`).
     * @param list<string> $overrides `key=value`/`key[]=value` pairs, in the same shape `parse_str()`
     *        itself accepts -- every override is joined into one string and parsed together, so
     *        several `key[]=...` occurrences accumulate into one array exactly as they would in a
     *        real query string or form body, rather than each replacing the last.
     * @return array<array-key, mixed>
     */
    private static function mergeFormEncoded(array $existing, array $overrides): array
    {
        parse_str(implode('&', $overrides), $incoming);

        return array_replace($existing, $incoming);
    }

    /**
     * Assigns $value onto $decoded at $key, JSON-decoding it first when it parses as JSON -- so
     * `--body count=3` sets an int and `--body active=true` sets a bool rather than the literal
     * string. A `key[]` suffix appends to (or, the first time this base key is seen in the current
     * batch -- tracked via $resetBases -- replaces) an array at the base key, the same shape
     * `parse_str()` gives a form body's `key[]=...`, so both merge strategies accept one syntax.
     *
     * @param array<array-key, mixed> $decoded
     * @param array<string, true> $resetBases Base keys already reset in this call's batch; mutated.
     */
    private static function assignJsonOverride(array &$decoded, string $key, string $value, array &$resetBases): void
    {
        $coerced = self::coerceJsonScalar($value);
        if (str_ends_with($key, '[]')) {
            $base = substr($key, 0, -2);
            $bucket = $decoded[$base] ?? null;
            if (!isset($resetBases[$base]) || !is_array($bucket)) {
                $bucket = [];
                $resetBases[$base] = true;
            }
            $bucket[] = $coerced;
            $decoded[$base] = $bucket;

            return;
        }
        $decoded[$key] = $coerced;
    }

    private static function coerceJsonScalar(string $value): mixed
    {
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    /**
     * @return array{0: string, 1: string}
     * @throws ReplayException if $override carries no `=`.
     */
    private static function splitOverride(string $override): array
    {
        $pos = strpos($override, '=');
        if ($pos === false) {
            throw new ReplayException(sprintf(
                '--query/--body override "%s" must be in key=value form.',
                $override,
            ));
        }

        return [substr($override, 0, $pos), substr($override, $pos + 1)];
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
