<?php

declare(strict_types=1);

namespace Quiote\Replay\Testing;

use Quiote\Config\Config;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Replay\IsolatedReplay;
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
 * Replays in isolation by default ({@see IsolatedReplay}), which is what makes
 * an emitted test safe to run unattended: every ledger-backed subsystem is
 * answered from the cassette's own recorded effects and nothing is performed,
 * so a recorded POST or DELETE re-runs on every CI build without re-performing
 * the write. It needs no configuration and no database, which is the promise
 * that made bypassing `ReplayEngine` right in the first place.
 *
 * `replay.tests_allow_live = true` opts a suite out, into a live dispatch
 * against whatever the test context is really configured with -- for the case
 * a test genuinely needs real collaborators. That is the whole decision: it
 * accepts real reads *and* real writes, on every run, and is only safe where
 * the environment is disposable. The isolated default is the protection;
 * turning it off is choosing not to have it.
 */
abstract class ReplayTestCase extends HttpTestCase
{
    /**
     * Reconstructs the request `$cassettePath` recorded and dispatches it
     * through the real pipeline, returning the response as a {@see TestResponse}.
     *
     * @throws \Quiote\Replay\Cassette\CassetteCodecException if the file is not a valid cassette.
     * @throws ReplayException if the cassette file cannot be read, carries no replayable
     *         request (e.g. a `#[NoRecord]` skeleton), or -- in isolation -- if the registered
     *         effect sources cannot serve from the ledger.
     */
    protected function replay(string $cassettePath): TestResponse
    {
        $cassette = self::loadCassette($cassettePath);
        $request = RequestReconstructor::fromCassette($cassette);

        if (!Config::getBool('replay.tests_allow_live', false)) {
            // The default, and the reason the method gate below is now only reachable on request:
            // an isolated replay performs nothing, so a recorded POST or DELETE is safe to run
            // unattended on every CI run -- which is exactly the situation an emitted test is for.
            return new TestResponse((new IsolatedReplay())->run($this->getContext(), $cassette, $request)->response);
        }

        // No further gate: `replay.tests_allow_live` is the decision. A suite that has turned the
        // isolated default off has said it wants real collaborators, and a second flag asking again
        // whether it *really* wants the write would be two knobs for one concern -- and would make
        // the opt-in useless for the case it exists for.
        return new TestResponse($this->getContext()->getRequestHandler()->handle($request));
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
