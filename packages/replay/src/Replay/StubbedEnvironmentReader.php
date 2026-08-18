<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Replay\Cassette\EffectKind;
use Quiote\Support\Environment\EnvironmentReaderInterface;

/**
 * The isolated-replay counterpart to
 * {@see \Quiote\Replay\Env\RecordingEnvironmentReader}: never reads a real
 * environment variable, answering every call from an injected
 * {@see EffectLedger} matched on the bare variable name.
 *
 * A variable with no matching recorded effect throws rather than fabricating
 * a value or falling through to the real `getenv()` -- inventing a value
 * would fabricate a passing test, the same rule
 * {@see StubbedCache}/{@see StubbedPdo}/{@see StubbedHttpTransport} follow. A
 * variable that WAS recorded as unset (`false`) replays as `false`, not an
 * exception -- that is itself the recorded, correct answer.
 */
final class StubbedEnvironmentReader implements EnvironmentReaderInterface
{
    public function __construct(private readonly EffectLedger $ledger)
    {
    }

    #[\Override]
    public function get(string $name): string|false
    {
        $effect = $this->ledger->match(EffectKind::Env, $name);
        if ($effect === null) {
            throw new \RuntimeException(sprintf('StubbedEnvironmentReader: no recorded env effect for "%s".', $name));
        }

        $result = $effect->result;
        if ($result !== false && !is_string($result)) {
            throw new \RuntimeException(sprintf('StubbedEnvironmentReader: recorded effect for "%s" is not a valid env value.', $name));
        }

        return $result;
    }
}
