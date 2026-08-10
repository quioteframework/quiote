<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Exception\QuioteException;

class ExceptionTest extends UnitTestCase
{
	public function testGetOriginalCodePreservesNonIntegerCode(): void
	{
		// The parent Exception constructor coerces non-int codes to 0, but
		// QuioteException keeps the original value accessible separately.
		$e = new QuioteException('message', 'CUSTOM_CODE');
		$this->assertSame('CUSTOM_CODE', $e->getOriginalCode());
		$this->assertSame(0, $e->getCode());
	}

	public function testGetOriginalCodeWithAnIntegerCode(): void
	{
		$e = new QuioteException('message', 42);
		$this->assertSame(42, $e->getOriginalCode());
		$this->assertSame(42, $e->getCode());
	}

	public function testTheCodeDefaultsToZero(): void
	{
		$e = new QuioteException('message');

		$this->assertSame(0, $e->getOriginalCode());
		$this->assertSame(0, $e->getCode());
	}

	/** The previous exception is what a renderer or log sink follows to report the root cause. */
	public function testThePreviousExceptionIsCarried(): void
	{
		$cause = new RuntimeException('root cause');
		$e = new QuioteException('wrapper', 0, $cause);

		$this->assertSame($cause, $e->getPrevious());
	}

	/**
	 * A string code has to survive being wrapped by a subclass, since that is
	 * where it comes from -- a driver-level code such as a PDO SQLSTATE.
	 */
	public function testASubclassKeepsTheStringCode(): void
	{
		$e = new \Quiote\Exception\DatabaseException('write failed', '42P01');

		$this->assertSame('42P01', $e->getOriginalCode());
		$this->assertSame(0, $e->getCode());
	}
}
