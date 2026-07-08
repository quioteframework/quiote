<?php

use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\ClientType;

class ClientTypeTest extends TestCase
{
	public function testCasesHaveExpectedScalarValues(): void
	{
		$this->assertSame('user', ClientType::User->value);
		$this->assertSame('service', ClientType::Service->value);
	}

	public function testFromRejectsUnknownValue(): void
	{
		$this->expectException(\ValueError::class);

		ClientType::from('unknown');
	}
}
