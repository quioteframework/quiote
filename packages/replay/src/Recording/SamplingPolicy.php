<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use Quiote\Support\Random\RandomnessInterface;
use RuntimeException;

/**
 * Which requests {@see RecorderMiddleware} keeps a cassette for. `Never` is
 * the default so installing the package changes nothing until
 * `replay.record` is set.
 */
enum SamplingPolicy: string
{
    case Never = 'never';
    case Error = 'error';
    case Rate = 'rate';
    case Header = 'header';
    case Always = 'always';

    /**
     * Resolves `replay.record`'s configured string to a policy. An
     * unrecognised value throws rather than silently falling back to
     * `never` -- the same rule `ratelimit.storage` follows, and for the same
     * reason: a typo must not silently enable or disable a security-relevant
     * feature.
     */
    public static function fromConfigValue(string $value): self
    {
        $policy = self::tryFrom($value);
        if ($policy === null) {
            throw new RuntimeException(sprintf(
                'replay.record is "%s"; expected one of: %s.',
                $value,
                implode(', ', array_map(static fn(self $case): string => $case->value, self::cases())),
            ));
        }

        return $policy;
    }

    /**
     * Whether this policy can already tell, at request entry, that nothing will be kept.
     *
     * Only {@see Rate} can: its decision is a coin flip that does not depend on the outcome, so
     * losing the flip up front means the whole capture -- the body copy, the upload digests, the
     * effect ledger -- can be skipped rather than performed and thrown away. {@see Error} and
     * {@see Header} genuinely need the response, and {@see Always}/{@see Never} are already
     * decided.
     *
     * The roll is passed in rather than taken here so `process()` makes it exactly once: rolling
     * again in `shouldKeep()` would sample twice at the configured rate and keep far fewer
     * requests than asked for.
     */
    public function declinesUpFront(float $sampleRate, RandomnessInterface $randomness, bool &$rolled): bool
    {
        if ($this !== self::Rate) {
            return false;
        }
        $rolled = $this->rollRate($sampleRate, $randomness);

        return !$rolled;
    }

    /**
     * Whether a request with the given outcome should be kept, under this
     * policy.
     *
     * `$status`/`$exceptionEscaped` are only meaningful for {@see Error}, and
     * `$sampleRate`/`$randomness` only for {@see Rate}, and `$headerPresent`
     * only for {@see Header} -- each policy reads only the parameters it
     * needs and ignores the rest.
     */
    public function shouldKeep(
        int $status,
        bool $exceptionEscaped,
        float $sampleRate,
        RandomnessInterface $randomness,
        bool $headerPresent,
        ?bool $rolled = null,
    ): bool {
        return match ($this) {
            self::Never => false,
            self::Always => true,
            self::Error => $exceptionEscaped || $status >= 500,
            self::Header => $headerPresent,
            // Reuses the roll made at entry when there was one, so a request is sampled once and
            // not twice.
            self::Rate => $rolled ?? $this->rollRate($sampleRate, $randomness),
        };
    }

    private function rollRate(float $sampleRate, RandomnessInterface $randomness): bool
    {
        if ($sampleRate <= 0.0) {
            return false;
        }
        if ($sampleRate >= 1.0) {
            return true;
        }

        return $randomness->int(0, 999_999) < (int)round($sampleRate * 1_000_000);
    }
}
