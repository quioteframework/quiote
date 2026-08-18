<?php

declare(strict_types=1);

namespace Quiote\Security\Csrf;

use Quiote\Support\Random\RandomnessInterface;
use Quiote\Support\Random\SystemRandomness;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;

/**
 * A drop-in replacement for Symfony's default
 * {@see \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator},
 * generating the same URI-safe base64 shape but through
 * {@see RandomnessInterface} instead of a direct `random_bytes()` call -- so a
 * cassette that records the {@see RandomnessInterface} reads behind a CSRF
 * token can reproduce that exact token value on replay, and a request whose
 * form POST depends on it does not fail the CSRF check purely because the
 * token could not be regenerated deterministically.
 */
final class RandomnessBackedTokenGenerator implements TokenGeneratorInterface
{
    /** @var int<8, max> */
    private readonly int $entropy;

    public function __construct(
        private readonly RandomnessInterface $randomness = new SystemRandomness(),
        int $entropy = 256,
    ) {
        if ($entropy <= 7) {
            throw new \InvalidArgumentException('Entropy should be greater than 7.');
        }
        $this->entropy = $entropy;
    }

    public function generateToken(): string
    {
        $byteLength = max(1, intdiv($this->entropy, 8));
        $bytes = $this->randomness->bytes($byteLength);

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
