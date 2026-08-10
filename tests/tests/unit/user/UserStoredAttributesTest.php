<?php

declare(strict_types=1);

use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\LogRegistry;
use Quiote\Logging\Sink\SinkInterface;
use Quiote\Session\SessionBagInterface;
use Quiote\Testing\UnitTestCase;
use Quiote\User\User;

/** Captures warnings and above, so a best-effort failure can be asserted on. */
final class UserAttributesCapturingSink implements SinkInterface
{
    /** @var list<LogEvent> */
    public array $captured = [];

    public function isEnabled(Level $level, string $category): bool
    {
        return $level->value >= Level::Warning->value;
    }

    public function emit(LogEvent $event): void
    {
        $this->captured[] = $event;
    }

    public function flush(): void
    {
    }
}

/**
 * How a User reads what the session holds for it, and writes back.
 *
 * The stored shape is a namespaced map, but a session written by an older
 * revision -- or by anything else that put a flat array under the user's
 * storage key -- has to keep working, because the alternative is every logged
 * in user losing their attributes on deploy.
 */
final class UserStoredAttributesTest extends UnitTestCase
{
    private const STORAGE_NAMESPACE = 'org.quiote.user.User';
    private const DEFAULT_NAMESPACE = 'org.quiote';

    private UserAttributesCapturingSink $sink;

    #[\Override]
    public function setUp(): void
    {
        $this->sink = new UserAttributesCapturingSink();
        Log::addSink($this->sink);
    }

    #[\Override]
    public function tearDown(): void
    {
        LogRegistry::reset();
    }

    /** Its own context per test, so a seeded session reaches nothing else. */
    private function contextWith(string $name, SessionBagInterface $bag): Context
    {
        $context = Context::getInstance('user-attributes::' . $name);
        $context->beginRequest();
        $context->getContainer()->set(SessionBagInterface::class, $bag, Container::SCOPE_REQUEST);

        return $context;
    }

    private function userReading(string $name, mixed $stored): User
    {
        $bag = new InMemorySessionBag();
        $bag->set(self::STORAGE_NAMESPACE, $stored);

        $user = new User();
        $user->initialize($this->contextWith($name, $bag));

        return $user;
    }

    // --- reading -----------------------------------------------------------

    public function testANamespacedMapIsReadAsItStands(): void
    {
        $user = $this->userReading('namespaced', [
            self::DEFAULT_NAMESPACE => ['name' => 'Markus'],
            'other.namespace' => ['flag' => true],
        ]);

        $this->assertSame('Markus', $user->getAttribute('name'));
        $this->assertTrue($user->getAttribute('flag', 'other.namespace'));
    }

    /**
     * A flat array under the storage key has no namespace layer, so it is
     * adopted wholesale into the default namespace rather than discarded.
     */
    public function testAFlatStoredArrayIsAdoptedIntoTheDefaultNamespace(): void
    {
        $user = $this->userReading('flat', ['name' => 'Markus', 'role' => 'admin']);

        $this->assertSame('Markus', $user->getAttribute('name'));
        $this->assertSame('admin', $user->getAttribute('role'));
    }

    /** Nothing stored, or something that is not an array, means no attributes. */
    public function testANonArrayStoredValueReadsAsNoAttributes(): void
    {
        foreach (['scalar' => 'not an array', 'null' => null, 'int' => 42] as $name => $stored) {
            $user = $this->userReading('non-array-' . $name, $stored);

            $this->assertSame([], $user->getAttributeNamespaces(), $name . ' must yield no namespaces');
            $this->assertNull($user->getAttribute('anything'), $name . ' must yield no attributes');
        }
    }

    /**
     * Within a namespaced map, an entry that is not a namespace-to-values pair
     * cannot be addressed by any getAttribute() call, so it is dropped rather
     * than carried as an unreachable value.
     */
    public function testMalformedEntriesInANamespacedMapAreDropped(): void
    {
        $user = $this->userReading('malformed', [
            self::DEFAULT_NAMESPACE => ['name' => 'Markus'],
            'valid.namespace' => ['ok' => true],
            'scalar.namespace' => 'not a values array',
            7 => ['numeric' => 'namespace'],
        ]);

        $this->assertSame('Markus', $user->getAttribute('name'));
        $this->assertTrue($user->getAttribute('ok', 'valid.namespace'));
        $this->assertSame([self::DEFAULT_NAMESPACE, 'valid.namespace'], $user->getAttributeNamespaces());
    }

    /** Rehydrating is not a mutation, so a freshly read user owes the session nothing. */
    public function testAUserThatOnlyReadItsAttributesIsNotDirty(): void
    {
        $user = $this->userReading('clean', [self::DEFAULT_NAMESPACE => ['name' => 'Markus']]);

        $this->assertFalse($user->isDirty());
    }

    /**
     * The default namespace's slice of what the user wrote to the session.
     *
     * @return array<array-key, mixed>
     */
    private function storedDefaultNamespace(InMemorySessionBag $bag): array
    {
        $stored = $bag->get(self::STORAGE_NAMESPACE);
        if (!is_array($stored)) {
            self::fail('the user persisted no attribute map at all');
        }

        $namespaced = $stored[self::DEFAULT_NAMESPACE] ?? null;
        if (!is_array($namespaced)) {
            self::fail('the persisted map has no default namespace');
        }

        return $namespaced;
    }

    // --- writing -----------------------------------------------------------

    public function testPersistingImmediatelyWritesTheWholeAttributeMap(): void
    {
        $bag = new InMemorySessionBag();
        $user = new User();
        $user->initialize($this->contextWith('persist-all', $bag));
        $user->setAttribute('name', 'Markus');

        $user->persistAttributesImmediate();

        $this->assertSame('Markus', $this->storedDefaultNamespace($bag)['name']);
        $this->assertFalse($user->isDirty(), 'a persisted user owes the session nothing further');
    }

    public function testPersistingSelectedKeysLeavesTheRestOfTheStoredMapAlone(): void
    {
        $bag = new InMemorySessionBag();
        $bag->set(self::STORAGE_NAMESPACE, [self::DEFAULT_NAMESPACE => ['keep' => 'existing']]);

        $user = new User();
        $user->initialize($this->contextWith('persist-some', $bag));
        $user->setAttribute('name', 'Markus');
        $user->setAttribute('ignored', 'not written');

        $user->persistAttributesImmediate(['name']);

        $stored = $this->storedDefaultNamespace($bag);
        $this->assertSame('Markus', $stored['name']);
        $this->assertSame('existing', $stored['keep']);
        $this->assertArrayNotHasKey('ignored', $stored);
    }

    /** A key the user does not hold is not invented in the stored map. */
    public function testPersistingAKeyTheUserDoesNotHoldWritesNothingForIt(): void
    {
        $bag = new InMemorySessionBag();
        $user = new User();
        $user->initialize($this->contextWith('persist-missing', $bag));

        $user->persistAttributesImmediate(['never_set']);

        $this->assertArrayNotHasKey('never_set', $this->storedDefaultNamespace($bag));
    }

    /**
     * The immediate write is an optimisation over the shutdown write, so a
     * session that will not take it degrades to "deferred" rather than
     * failing the request -- but it has to say so, since the value is only in
     * memory until shutdown.
     */
    public function testAFailedImmediatePersistIsReportedAndDeferredRatherThanThrown(): void
    {
        $user = new User();
        $user->initialize($this->contextWith('persist-fails', new InMemorySessionBag()));
        $user->setAttribute('name', 'Markus');

        // Swap in a bag that refuses writes only now, so initialize() still worked.
        Context::getInstance('user-attributes::persist-fails')->getContainer()->set(
            SessionBagInterface::class,
            new FailingSessionBag(),
            Container::SCOPE_REQUEST,
        );

        $user->persistAttributesImmediate();

        $messages = array_map(static fn(LogEvent $e): string => $e->renderMessage(), $this->sink->captured);
        $joined = implode(' | ', $messages);

        $this->assertStringContainsString('deferring to shutdown', $joined);
        $this->assertStringContainsString(self::STORAGE_NAMESPACE, $joined);
        $this->assertTrue($user->isDirty(), 'the value is still owed to the session');
    }
}
