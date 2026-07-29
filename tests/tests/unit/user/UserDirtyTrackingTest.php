<?php

use PHPUnit\Framework\Attributes\DataProvider;
use Quiote\Context;
use Quiote\Testing\UnitTestCase;
use Quiote\User\User;

/**
 * Dirty tracking on the user hierarchy: a user that was only rehydrated from
 * storage writes nothing at the request boundary.
 *
 * Before this, User/SecurityUser/RbacSecurityUser all wrote unconditionally at
 * shutdown, and Storage::store() lazily creates a session -- so every request
 * that reached the framework left a session row behind, health checks, bots and
 * read-only API calls included. That write volume is also what made the SQLite
 * lock contention fire so readily.
 */
class UserDirtyTrackingTest extends UnitTestCase
{
    private function user(): User
    {
        $user = new User();
        $user->initialize($this->getContext());

        return $user;
    }

    public function testAFreshlyInitializedUserIsClean(): void
    {
        $this->assertFalse($this->user()->isDirty());
    }

    /**
     * Every attribute mutator must mark the user dirty; three of these take
     * their value by reference and one returns by reference, so the overrides
     * have to reproduce those signatures exactly.
     */
    #[DataProvider('attributeMutators')]
    public function testEachAttributeMutatorMarksDirty(string $label, callable $mutate): void
    {
        $user = $this->user();
        // Seed something to remove/clear, without going through a mutator.
        $user->setAttribute('seeded', 'value');
        $user->markClean();
        $this->assertFalse($user->isDirty(), 'precondition for ' . $label);

        $mutate($user);

        $this->assertTrue($user->isDirty(), $label . ' must mark the user dirty');
    }

    /**
     * @return array<string, array{0: string, 1: callable}>
     */
    public static function attributeMutators(): array
    {
        return [
            'setAttribute' => ['setAttribute', static fn(User $u) => $u->setAttribute('k', 'v')],
            'appendAttribute' => ['appendAttribute', static fn(User $u) => $u->appendAttribute('k', 'v')],
            'setAttributes' => ['setAttributes', static fn(User $u) => $u->setAttributes(['k' => 'v'])],
            'clearAttributes' => ['clearAttributes', static fn(User $u) => $u->clearAttributes()],
            'removeAttribute' => ['removeAttribute', static function (User $u): void { $u->removeAttribute('seeded'); }],
            'removeAttributeNamespace' => ['removeAttributeNamespace', static fn(User $u) => $u->removeAttributeNamespace($u->getDefaultNamespace())],
            'setAttributeByRef' => ['setAttributeByRef', static function (User $u): void { $v = 'v'; $u->setAttributeByRef('k', $v); }],
            'appendAttributeByRef' => ['appendAttributeByRef', static function (User $u): void { $v = 'v'; $u->appendAttributeByRef('k', $v); }],
            'setAttributesByRef' => ['setAttributesByRef', static function (User $u): void { $a = ['k' => 'v']; $u->setAttributesByRef($a); }],
        ];
    }

    public function testRemoveAttributeStillReturnsByReference(): void
    {
        $user = $this->user();
        $user->setAttribute('k', 'original');

        $removed =& $user->removeAttribute('k');

        $this->assertSame('original', $removed);
    }

    public function testShutdownOnACleanUserWritesNothing(): void
    {
        $context = Context::getInstance('user-dirty-test::tests-anonymous');
        $bag = new InMemorySessionBag();
        $context->setSessionBag($bag);

        $user = new User();
        $user->initialize($context);
        $this->assertFalse($user->isDirty());

        $user->shutdown();

        $this->assertFalse($bag->has('org.quiote.user.User'), 'a clean user must not write');
    }

    public function testShutdownOnADirtyUserWritesAndThenMarksClean(): void
    {
        $context = Context::getInstance('user-dirty-test::tests-anonymous');
        $bag = new InMemorySessionBag();
        $context->setSessionBag($bag);

        $user = new User();
        $user->initialize($context);
        $user->setAttribute('userId', 42);

        $user->shutdown();

        $this->assertTrue($bag->has('org.quiote.user.User'));
        $this->assertFalse($user->isDirty(), 'a persisted user is clean again');
    }

    public function testASecondShutdownAfterASuccessfulWriteIsANoOp(): void
    {
        $context = Context::getInstance('user-dirty-test::tests-anonymous');
        $bag = new InMemorySessionBag();
        $context->setSessionBag($bag);

        $user = new User();
        $user->initialize($context);
        $user->setAttribute('userId', 42);

        $user->shutdown();
        $user->shutdown();
        $user->shutdown();

        $this->assertSame(1, $bag->writes);
    }

    /**
     * Failure path: a store that throws leaves the state unpersisted, so the
     * user must stay dirty and a later flush must retry rather than drop it.
     */
    public function testShutdownDoesNotMarkCleanWhenTheStoreThrows(): void
    {
        $context = Context::getInstance('user-dirty-test::tests-anonymous');
        $context->setSessionBag(new ThrowingSessionBag());

        $user = new User();
        $user->initialize($context);
        $user->setAttribute('userId', 42);

        try {
            $user->shutdown();
        } catch (\Throwable) {
            // the throw itself is not what is under test
        }

        $this->assertTrue($user->isDirty(), 'an unpersisted user must remain dirty so a later flush retries');
    }

    public function testResetClearsTheDirtyFlag(): void
    {
        $user = $this->user();
        $user->setAttribute('k', 'v');
        $this->assertTrue($user->isDirty());

        $user->reset();

        $this->assertFalse($user->isDirty());
    }

    public function testASerializedSnapshotDoesNotCarryTheDirtyFlag(): void
    {
        $user = $this->user();
        $user->setAttribute('k', 'v');

        $this->assertNotContains('dirty', $user->__sleep(), 'a restored snapshot describes already-persisted state');
    }

    public function testRestoreContextLeavesTheUserClean(): void
    {
        $user = $this->user();
        $user->setAttribute('k', 'v');

        $user->restoreContext($this->getContext());

        $this->assertFalse($user->isDirty(), 'reshaping a restored snapshot is not a mutation');
    }

    public function testMarkDirtyIsTheEscapeValveForDirectStateMutation(): void
    {
        $user = $this->user();
        $this->assertFalse($user->isDirty());

        $user->markDirty();

        $this->assertTrue($user->isDirty());
    }
}

/**
 * A bag whose writes always fail, for the "unpersisted state stays dirty" path.
 */
class ThrowingSessionBag extends InMemorySessionBag
{
    #[\Override]
    public function set(string $key, mixed $value): void
    {
        throw new \RuntimeException('session backend is down');
    }
}
