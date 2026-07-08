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
		// NullStorage (the default test storage) discards everything; a
		// plain in-memory stand-in lets store()/consume() actually round-trip.
		$ctx = $this->getContext();
		$ro = new ReflectionObject($ctx);
		$prop = $ro->getProperty('storage');
		$prop->setValue($ctx, new class {
			/** @var array<string, mixed> */
			private array $data = [];
			public function store(string $id, mixed $data): bool { $this->data[$id] = $data; return true; }
			public function retrieve(string $key): mixed { return $this->data[$key] ?? null; }
			public function remove(string $key): void { unset($this->data[$key]); }
		});
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
