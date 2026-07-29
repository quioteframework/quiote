<?php

use Quiote\Security\Auth\OidcAuthorizationState;
use Quiote\Security\Auth\OidcStateStorage;
use Quiote\Testing\UnitTestCase;

class OidcStateStorageTest extends UnitTestCase
{
	#[\Override]
    protected function setUp(): void
	{
		parent::setUp();
		$ctx = $this->getContext();
		$ctx->setSessionBag(new InMemorySessionBag());
	}

	public function testConsumeReturnsAPreviouslyStoredState(): void
	{
		$storage = new OidcStateStorage($this->getContext());
		$state = new OidcAuthorizationState('state-1', 'verifier-1', 'nonce-1');

		$storage->store($state);
		$consumed = $storage->consume('state-1');

		$this->assertNotNull($consumed);
		$this->assertSame('state-1', $consumed->getState());
		$this->assertSame('verifier-1', $consumed->getPkceVerifier());
		$this->assertSame('nonce-1', $consumed->getNonce());
	}

	public function testConsumeReturnsNullForAnUnknownState(): void
	{
		$storage = new OidcStateStorage($this->getContext());

		$this->assertNull($storage->consume('never-stored'));
	}

	public function testConsumeRemovesTheEntrySoItCannotBeReplayed(): void
	{
		$storage = new OidcStateStorage($this->getContext());
		$storage->store(new OidcAuthorizationState('state-1', 'verifier-1', 'nonce-1'));

		$storage->consume('state-1');

		$this->assertNull($storage->consume('state-1'));
	}
}
