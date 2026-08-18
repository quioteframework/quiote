<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Security\Csrf\RandomnessBackedTokenGenerator;
use Quiote\Support\Random\SeededRandomness;
use Quiote\Support\Random\SystemRandomness;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;

final class RandomnessBackedTokenGeneratorTest extends TestCase
{
    public function testImplementsTokenGeneratorInterface(): void
    {
        $this->assertInstanceOf(TokenGeneratorInterface::class, new RandomnessBackedTokenGenerator());
    }

    public function testGeneratesAUriSafeToken(): void
    {
        $generator = new RandomnessBackedTokenGenerator(new SystemRandomness());

        $token = $generator->generateToken();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        $this->assertStringNotContainsString('=', $token);
    }

    public function testSameSeedProducesTheSameTokenAcrossTwoGenerators(): void
    {
        $a = new RandomnessBackedTokenGenerator(new SeededRandomness(42));
        $b = new RandomnessBackedTokenGenerator(new SeededRandomness(42));

        $this->assertSame($a->generateToken(), $b->generateToken());
    }

    public function testDifferentSeedsProduceDifferentTokens(): void
    {
        $a = new RandomnessBackedTokenGenerator(new SeededRandomness(1));
        $b = new RandomnessBackedTokenGenerator(new SeededRandomness(2));

        $this->assertNotSame($a->generateToken(), $b->generateToken());
    }

    public function testEntropyControlsTheTokenLength(): void
    {
        $generator = new RandomnessBackedTokenGenerator(new SystemRandomness(), 128);

        // 128 bits = 16 bytes, base64url-encoded (no padding) is ceil(16/3*4) = 22 chars.
        $this->assertSame(22, strlen($generator->generateToken()));
    }

    public function testRejectsEntropyOfEightOrFewerBits(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RandomnessBackedTokenGenerator(new SystemRandomness(), 7);
    }
}
