<?php

declare(strict_types=1);

namespace Quiote\Replay\Testing;

use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Replay\ReplayException;
use Quiote\Replay\Replay\RequestReconstructor;
use Quiote\Testing\Http\TestResponse;
use Quiote\Testing\HttpTestCase;

/**
 * Base class an emitted `--as-test` test extends: `replay()` reconstructs a
 * cassette's request and dispatches it through the same real pipeline
 * {@see HttpTestCase}'s own `get()`/`post()`/etc. helpers use
 * (`getContext()->getRequestHandler()->handle()`), returning the same
 * {@see TestResponse} the rest of the suite already asserts against.
 *
 * Deliberately does **not** go through {@see \Quiote\Replay\Replay\ReplayEngine}:
 * that class exists for the interactive `quiote replay <id>` CLI workflow and
 * enforces `replay.allow_live`/non-idempotent-method safety gates appropriate
 * to a developer pointing the command at a real, possibly-shared application.
 * An emitted test is the opposite case -- a committed regression test that
 * must run unattended in CI with no extra configuration: it needs the
 * package installed for `ReplayTestCase`, and nothing else. Requiring
 * `replay.allow_live=true` in every CI environment, or `--force` for the
 * common case of a recorded POST/PUT request, would break that promise
 * outright.
 */
abstract class ReplayTestCase extends HttpTestCase
{
    /**
     * Reconstructs the request `$cassettePath` recorded and dispatches it
     * through the real pipeline, returning the response as a {@see TestResponse}.
     *
     * @throws \Quiote\Replay\Cassette\CassetteCodecException if the file is not a valid cassette.
     * @throws ReplayException if the cassette file cannot be read, or carries no replayable
     *         request (e.g. a `#[NoRecord]` skeleton).
     */
    protected function replay(string $cassettePath): TestResponse
    {
        $cassette = self::loadCassette($cassettePath);
        $request = RequestReconstructor::fromCassette($cassette);
        $response = $this->getContext()->getRequestHandler()->handle($request);

        return new TestResponse($response);
    }

    private static function loadCassette(string $path): Cassette
    {
        $blob = @file_get_contents($path);
        if ($blob === false) {
            throw new ReplayException(sprintf('Could not read cassette file "%s".', $path));
        }

        return (new CassetteCodec())->decode($blob);
    }
}
