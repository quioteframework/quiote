<?php

use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\Hasher\DefaultPasswordHasher;

class DefaultPasswordHasherTest extends TestCase
{
	public function testHashProducesAVerifiableHash(): void
	{
		$hasher = new DefaultPasswordHasher();
		$hash = $hasher->hash('correct horse battery staple');

		$this->assertTrue($hasher->verify('correct horse battery staple', $hash));
	}

	public function testVerifyRejectsWrongPlaintext(): void
	{
		$hasher = new DefaultPasswordHasher();
		$hash = $hasher->hash('correct horse battery staple');

		$this->assertFalse($hasher->verify('wrong password', $hash));
	}

	public function testNeedsRehashIsFalseForAFreshHash(): void
	{
		$hasher = new DefaultPasswordHasher();

		$this->assertFalse($hasher->needsRehash($hasher->hash('secret')));
	}

	public function testNeedsRehashIsTrueWhenAlgorithmChanges(): void
	{
		$bcryptHasher = new DefaultPasswordHasher(PASSWORD_BCRYPT);
		$hash = $bcryptHasher->hash('secret');

		if(!defined('PASSWORD_ARGON2ID')) {
			$this->markTestSkipped('argon2id not available in this PHP build');
		}

		$argonHasher = new DefaultPasswordHasher(PASSWORD_ARGON2ID);
		$this->assertTrue($argonHasher->needsRehash($hash));
	}

	public function testConstructorRejectsAnUnsupportedAlgorithm(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new DefaultPasswordHasher('not-a-real-algorithm');
	}
}
