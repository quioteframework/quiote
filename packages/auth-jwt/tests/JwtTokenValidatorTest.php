<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\AuthenticationException;
use Quiote\Security\Auth\JwtTokenValidator;

class JwtTokenValidatorTest extends TestCase
{
	private const SECRET = 'test-shared-secret-at-least-32-bytes-long';

	/** @param array<string, mixed> $claims */
	private function encode(array $claims): string
	{
		return JWT::encode($claims, self::SECRET, 'HS256');
	}

	private function validator(int $leeway = 60): JwtTokenValidator
	{
		return new JwtTokenValidator(new Key(self::SECRET, 'HS256'), 'https://issuer.example.com', 'my-api', $leeway);
	}

	public function testValidateReturnsClaimsForAValidToken(): void
	{
		$token = $this->encode([
			'sub' => 'user-1',
			'iss' => 'https://issuer.example.com',
			'aud' => 'my-api',
			'exp' => time() + 60,
		]);

		$claims = $this->validator()->validate($token);

		$this->assertSame('user-1', $claims['sub']);
		$this->assertSame('https://issuer.example.com', $claims['iss']);
	}

	public function testValidateAcceptsAnAudienceWithinAnArray(): void
	{
		$token = $this->encode([
			'sub' => 'user-1',
			'iss' => 'https://issuer.example.com',
			'aud' => ['other-api', 'my-api'],
			'exp' => time() + 60,
		]);

		$claims = $this->validator()->validate($token);

		$this->assertSame('user-1', $claims['sub']);
	}

	public function testValidateThrowsOnBadSignature(): void
	{
		$token = JWT::encode(['sub' => 'user-1', 'iss' => 'https://issuer.example.com', 'aud' => 'my-api', 'exp' => time() + 60], 'a-completely-different-secret-value', 'HS256');

		$this->expectException(AuthenticationException::class);
		$this->validator()->validate($token);
	}

	public function testValidateThrowsOnExpiredToken(): void
	{
		$token = $this->encode([
			'sub' => 'user-1',
			'iss' => 'https://issuer.example.com',
			'aud' => 'my-api',
			'exp' => time() - 3600,
		]);

		$this->expectException(AuthenticationException::class);
		$this->validator(leeway: 0)->validate($token);
	}

	public function testValidateThrowsOnWrongIssuer(): void
	{
		$token = $this->encode([
			'sub' => 'user-1',
			'iss' => 'https://attacker.example.com',
			'aud' => 'my-api',
			'exp' => time() + 60,
		]);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('issuer');
		$this->validator()->validate($token);
	}

	public function testValidateThrowsOnWrongAudience(): void
	{
		$token = $this->encode([
			'sub' => 'user-1',
			'iss' => 'https://issuer.example.com',
			'aud' => 'someone-elses-api',
			'exp' => time() + 60,
		]);

		$this->expectException(AuthenticationException::class);
		$this->expectExceptionMessage('audience');
		$this->validator()->validate($token);
	}

	public function testValidateThrowsOnMalformedToken(): void
	{
		$this->expectException(AuthenticationException::class);
		$this->validator()->validate('not-a-jwt');
	}
}
