<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use Quiote\Support\Random\RandomnessInterface;
use RuntimeException;

/**
 * The {@see SamplingPolicy} values `replay.record` names, combined: a request is kept when any one
 * of them keeps it, so `error,header` records every failing request *and* every request that asks
 * to be recorded.
 *
 * `replay.record` is read as a list -- an array, a comma-separated string, or both -- and each
 * entry must name a policy. `never` cannot be combined with anything, since a list that says both
 * "record nothing" and "record this" is a contradiction a typo is more likely to have produced than
 * intent; an empty list is rejected for the same reason an unrecognised value is.
 */
final readonly class SamplingPolicySet
{
    /**
     * The order {@see keptBy()} asks members in, and so which member a cassette is attributed to
     * when several would keep it. `error` comes first because the `trigger` it stamps is what a
     * store reads to report a cassette at error level; `header` next, because someone explicitly
     * asked for that request; the unconditional ones last.
     */
    private const array PRECEDENCE = [
        SamplingPolicy::Error,
        SamplingPolicy::Header,
        SamplingPolicy::Rate,
        SamplingPolicy::Always,
    ];

    /** @param list<SamplingPolicy> $policies Ordered by {@see PRECEDENCE}, without duplicates or `Never`. */
    private function __construct(private array $policies)
    {
    }

    public static function never(): self
    {
        return new self([]);
    }

    /**
     * Resolves `replay.record`'s configured entries. Each entry may itself be a comma-separated
     * list, so the same setting works from an array in config and from a single env-var string.
     *
     * @param list<string> $values
     * @throws RuntimeException When an entry is unrecognised, when `never` is combined with
     *                          another policy, or when nothing is named at all.
     */
    public static function fromConfigValues(array $values): self
    {
        $named = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $named[] = SamplingPolicy::fromConfigValue($part);
                }
            }
        }

        if ($named === []) {
            throw new RuntimeException(sprintf(
                'replay.record names no policy; expected one or more of: %s.',
                implode(', ', array_map(static fn(SamplingPolicy $case): string => $case->value, SamplingPolicy::cases())),
            ));
        }

        if (in_array(SamplingPolicy::Never, $named, true)) {
            if (array_filter($named, static fn(SamplingPolicy $policy): bool => $policy !== SamplingPolicy::Never) !== []) {
                throw new RuntimeException('replay.record combines "never" with another policy; use "never" on its own or remove it.');
            }

            return self::never();
        }

        return new self(array_values(array_filter(
            self::PRECEDENCE,
            static fn(SamplingPolicy $policy): bool => in_array($policy, $named, true),
        )));
    }

    /** Whether no request can ever be kept, so the recorder can step aside entirely. */
    public function isNever(): bool
    {
        return $this->policies === [];
    }

    /** @return list<SamplingPolicy> */
    public function policies(): array
    {
        return $this->policies;
    }

    /**
     * Whether it is already known, at request entry, that no member will keep this request.
     *
     * Every member is asked, not just until the first that cannot decide yet: `rate` makes its one
     * roll here, and {@see keptBy()} reuses that roll, so skipping it would leave `rate` to roll a
     * second time later and sample at the wrong rate.
     */
    public function declinesUpFront(float $sampleRate, RandomnessInterface $randomness, bool &$rolled): bool
    {
        $declines = true;
        foreach ($this->policies as $policy) {
            $declines = $policy->declinesUpFront($sampleRate, $randomness, $rolled) && $declines;
        }

        return $declines;
    }

    /**
     * The member that keeps a request with this outcome, first by {@see PRECEDENCE}, or null when
     * none does.
     */
    public function keptBy(
        int $status,
        bool $exceptionEscaped,
        float $sampleRate,
        RandomnessInterface $randomness,
        bool $headerPresent,
        ?bool $rolled = null,
    ): ?SamplingPolicy {
        foreach ($this->policies as $policy) {
            if ($policy->shouldKeep($status, $exceptionEscaped, $sampleRate, $randomness, $headerPresent, $rolled)) {
                return $policy;
            }
        }

        return null;
    }
}
