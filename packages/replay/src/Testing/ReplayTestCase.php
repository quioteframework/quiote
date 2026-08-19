<?php

declare(strict_types=1);

namespace Quiote\Replay\Testing;

use Quiote\Config\Config;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Replay\ReplayEngine;
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
 * enforces `replay.allow_live` -- appropriate to a developer pointing the
 * command at a real, possibly-shared application, and wrong for a committed
 * regression test that must run unattended in CI with nothing configured
 * beyond having the package installed.
 *
 * The *method* gate is a different matter and is enforced here too. Replay has
 * no effect stubbing yet, so it really re-performs the request against
 * whatever database, queue and HTTP client the test context is configured
 * with. Dropping that gate on the automated path meant `--as-test` on a
 * recorded POST or DELETE produced a test that re-performed that write on
 * every CI run, forever -- the interactive path a human watches had rails and
 * the unattended path nobody watches had none, which is backwards. A safe
 * method replays with no configuration, as intended; anything else needs
 * `replay.tests_allow_live` set deliberately by whoever knows the test
 * environment is disposable.
 */
abstract class ReplayTestCase extends HttpTestCase
{
    /**
     * Reconstructs the request `$cassettePath` recorded and dispatches it
     * through the real pipeline, returning the response as a {@see TestResponse}.
     *
     * @throws \Quiote\Replay\Cassette\CassetteCodecException if the file is not a valid cassette.
     * @throws ReplayException if the cassette file cannot be read, carries no replayable
     *         request (e.g. a `#[NoRecord]` skeleton), or records a non-safe method while
     *         `replay.tests_allow_live` is off.
     */
    protected function replay(string $cassettePath): TestResponse
    {
        $cassette = self::loadCassette($cassettePath);
        $request = RequestReconstructor::fromCassette($cassette);
        self::assertMethodIsReplayable($request->getMethod(), $cassettePath);
        $response = $this->getContext()->getRequestHandler()->handle($request);

        return new TestResponse($response);
    }

    /**
     * @throws ReplayException if replaying $method would re-perform a write that nothing has
     *         opted into.
     */
    private static function assertMethodIsReplayable(string $method, string $cassettePath): void
    {
        if (ReplayEngine::isSafeMethod($method) || Config::getBool('replay.tests_allow_live', false)) {
            return;
        }

        throw new ReplayException(sprintf(
            'Refusing to replay the %s request recorded in "%s": replay has no effect stubbing '
            . 'yet, so dispatching it really re-performs its writes against whatever database, '
            . 'queue and HTTP client this test context is configured with -- on every run. Set '
            . 'replay.tests_allow_live=true for this suite only once the test environment is '
            . 'disposable, or replace the $this->replay() call with assertions that do not need '
            . 'to re-issue the write.',
            strtoupper($method),
            basename($cassettePath),
        ));
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
