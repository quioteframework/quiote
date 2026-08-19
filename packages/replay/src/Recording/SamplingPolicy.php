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
    ): bool {
        return match ($this) {
            self::Never => false,
            self::Always => true,
            self::Error => $exceptionEscaped || $status >= 500,
            self::Header => $headerPresent,
            self::Rate => $this->rollRate($sampleRate, $randomness),
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
