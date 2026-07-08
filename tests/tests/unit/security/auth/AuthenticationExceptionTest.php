<?php

use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\AuthenticationException;

class AuthenticationExceptionTest extends TestCase
{
	public function testCarriesMessageAndIsARuntimeException(): void
	{
		$exception = new AuthenticationException('invalid credentials');

		$this->assertInstanceOf(\RuntimeException::class, $exception);
		$this->assertSame('invalid credentials', $exception->getMessage());
	}

	public function testCarriesPreviousExceptionForChaining(): void
	{
		$previous = new \InvalidArgumentException('malformed token');
		$exception = new AuthenticationException('invalid credentials', 0, $previous);

		$this->assertSame($previous, $exception->getPrevious());
	}
}
