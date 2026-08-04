<?php

use Quiote\ShutdownSequence;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The sequence is pure ordering logic with no context involved, so every case here is direct.
 */
class ShutdownSequenceTest extends PhpUnitTestCase
{
	private function component(string $label): object
	{
		return new ShutdownSequenceTestComponent($label);
	}

	public function testANewSequenceIsEmpty(): void
	{
		$sequence = new ShutdownSequence();

		$this->assertSame([], $sequence->all());
		$this->assertSame(0, $sequence->count());
	}

	public function testReplaceAllInstallsTheListInOrder(): void
	{
		$sequence = new ShutdownSequence();
		$a = $this->component('a');
		$b = $this->component('b');

		$sequence->replaceAll([$a, $b]);

		$this->assertSame([$a, $b], $sequence->all());
		$this->assertSame(2, $sequence->count());
	}

	public function testReplaceAllDiscardsThePreviousContents(): void
	{
		$sequence = new ShutdownSequence();
		$sequence->replaceAll([$this->component('old')]);
		$new = $this->component('new');

		$sequence->replaceAll([$new]);

		$this->assertSame([$new], $sequence->all());
	}

	/**
	 * The generated factory cache lists every configured slot, and an optional slot the
	 * application did not configure is null there. A null in the sequence would be something
	 * nothing can shut down.
	 */
	public function testReplaceAllDropsNonObjectsAndReindexes(): void
	{
		$sequence = new ShutdownSequence();
		$a = $this->component('a');
		$b = $this->component('b');

		$sequence->replaceAll([$a, null, $b, null]);

		$this->assertSame([$a, $b], $sequence->all());
		$this->assertSame([0, 1], array_keys($sequence->all()));
	}

	public function testAppendAddsAtTheEnd(): void
	{
		$sequence = new ShutdownSequence();
		$a = $this->component('a');
		$b = $this->component('b');
		$sequence->replaceAll([$a]);

		$sequence->append($b);

		$this->assertSame([$a, $b], $sequence->all());
	}

	/**
	 * Appending something already present must not give it a second shutdown.
	 */
	public function testAppendIsIdempotentByIdentity(): void
	{
		$sequence = new ShutdownSequence();
		$a = $this->component('a');
		$sequence->replaceAll([$a]);

		$sequence->append($a);

		$this->assertSame([$a], $sequence->all());
	}

	public function testHasAnswersByIdentityNotEquality(): void
	{
		$sequence = new ShutdownSequence();
		$a = $this->component('same-label');
		$sequence->replaceAll([$a]);

		$this->assertTrue($sequence->has($a));
		$this->assertFalse(
			$sequence->has($this->component('same-label')),
			'a distinct but equal component is not in the sequence',
		);
	}

	public function testRemoveDropsEveryMatchAndClosesTheGaps(): void
	{
		$sequence = new ShutdownSequence();
		$keep = $this->component('keep');
		$sequence->replaceAll([$this->component('drop'), $keep, $this->component('drop')]);

		$sequence->remove(
			static fn(object $c): bool => $c instanceof ShutdownSequenceTestComponent
				&& $c->label === 'drop',
		);

		$this->assertSame([$keep], $sequence->all());
		$this->assertSame([0], array_keys($sequence->all()));
	}

	public function testRemoveMatchingNothingChangesNothing(): void
	{
		$sequence = new ShutdownSequence();
		$a = $this->component('a');
		$sequence->replaceAll([$a]);

		$sequence->remove(static fn(object $c): bool => false);

		$this->assertSame([$a], $sequence->all());
	}

	/**
	 * The invariant the whole class exists for: a replacement takes the role's original position,
	 * not the front and not the back. Moving it would shut it down before components whose late
	 * mutations it is supposed to capture.
	 */
	public function testReplaceRoleKeepsTheRolesOriginalPosition(): void
	{
		$sequence = new ShutdownSequence();
		$first = $this->component('first');
		$stale = $this->component('role');
		$last = $this->component('last');
		$sequence->replaceAll([$first, $stale, $last]);
		$fresh = $this->component('role');

		$sequence->replaceRole($fresh, static fn(object $c): bool => $c === $stale);

		$this->assertSame([$first, $fresh, $last], $sequence->all());
	}

	/**
	 * The stale-component problem this solves: the worker boundary leaves dead instances behind,
	 * and every one of them must give way to the single live replacement.
	 */
	public function testReplaceRoleCollapsesEveryStaleInstanceIntoOne(): void
	{
		$sequence = new ShutdownSequence();
		$other = $this->component('other');
		$sequence->replaceAll([
			$this->component('role'),
			$other,
			$this->component('role'),
		]);
		$fresh = $this->component('role');

		$sequence->replaceRole(
			$fresh,
			static fn(object $c): bool => $c instanceof ShutdownSequenceTestComponent
				&& $c->label === 'role',
		);

		$this->assertSame([$fresh, $other], $sequence->all(), 'collapsed to the first position');
		$this->assertSame(2, $sequence->count());
	}

	public function testReplaceRoleUsesTheFallbackIndexWhenTheRoleIsAbsent(): void
	{
		$sequence = new ShutdownSequence();
		$a = $this->component('a');
		$b = $this->component('b');
		$sequence->replaceAll([$a, $b]);
		$fresh = $this->component('role');

		$sequence->replaceRole($fresh, static fn(object $c): bool => false, fallbackIndex: 1);

		$this->assertSame([$a, $fresh, $b], $sequence->all());
	}

	/**
	 * The default: first, so the component's writes land before anything else in the sequence.
	 */
	public function testReplaceRoleDefaultsToTheFrontWhenTheRoleIsAbsent(): void
	{
		$sequence = new ShutdownSequence();
		$a = $this->component('a');
		$sequence->replaceAll([$a]);
		$fresh = $this->component('role');

		$sequence->replaceRole($fresh, static fn(object $c): bool => false);

		$this->assertSame([$fresh, $a], $sequence->all());
	}

	public function testReplaceRoleClampsAFallbackIndexPastTheEnd(): void
	{
		$sequence = new ShutdownSequence();
		$a = $this->component('a');
		$sequence->replaceAll([$a]);
		$fresh = $this->component('role');

		$sequence->replaceRole($fresh, static fn(object $c): bool => false, fallbackIndex: 99);

		$this->assertSame([$a, $fresh], $sequence->all());
	}

	public function testReplaceRoleIntoAnEmptySequenceJustAddsIt(): void
	{
		$sequence = new ShutdownSequence();
		$fresh = $this->component('role');

		$sequence->replaceRole($fresh, static fn(object $c): bool => false);

		$this->assertSame([$fresh], $sequence->all());
	}

	/**
	 * A throwing predicate degrades the ordering, not the caller: the component's own persistence
	 * is driven directly by the context's reset/shutdown.
	 */
	public function testReplaceRoleSwallowsAThrowingPredicate(): void
	{
		$sequence = new ShutdownSequence();
		$sequence->replaceAll([$this->component('a')]);

		$sequence->replaceRole(
			$this->component('role'),
			static fn(object $c): bool => throw new \RuntimeException('predicate exploded'),
		);

		$this->addToAssertionCount(1);
	}

	public function testShutdownAllShutsEveryComponentDownInOrder(): void
	{
		$sequence = new ShutdownSequence();
		$log = new ShutdownLog();
		$sequence->replaceAll([
			new ShutdownSequenceTestComponent('a', $log),
			new ShutdownSequenceTestComponent('b', $log),
		]);

		$sequence->shutdownAll();

		$this->assertSame(['a', 'b'], $log->entries());
	}

	/**
	 * The user is persisted by the request-state flush, which owns the ordering against the
	 * session. Shutting it down here too would double-write.
	 */
	public function testShutdownAllSkipsTheNamedComponent(): void
	{
		$sequence = new ShutdownSequence();
		$log = new ShutdownLog();
		$skipped = new ShutdownSequenceTestComponent('skipped', $log);
		$sequence->replaceAll([$skipped, new ShutdownSequenceTestComponent('other', $log)]);

		$sequence->shutdownAll(skip: $skipped);

		$this->assertSame(['other'], $log->entries());
	}

	/**
	 * A component with no shutdown() at all is simply passed over -- the sequence holds whatever
	 * the factory configuration listed.
	 */
	public function testShutdownAllIgnoresAComponentWithoutAShutdownMethod(): void
	{
		$sequence = new ShutdownSequence();
		$log = new ShutdownLog();
		$sequence->replaceAll([
			new stdClass(),
			new ShutdownSequenceTestComponent('real', $log),
		]);

		$sequence->shutdownAll();

		$this->assertSame(['real'], $log->entries());
	}

	/**
	 * A shutdown error must not mask whatever was executing when shutdown began, and must not
	 * stop the components after it from shutting down.
	 */
	public function testShutdownAllContinuesPastAThrowingComponent(): void
	{
		$sequence = new ShutdownSequence();
		$log = new ShutdownLog();
		$sequence->replaceAll([
			new ThrowingShutdownSequenceTestComponent(new \RuntimeException('boom')),
			new ShutdownSequenceTestComponent('after', $log),
		]);

		$sequence->shutdownAll();

		$this->assertSame(['after'], $log->entries(), 'the component after the failure still shut down');
	}

	/**
	 * Errors, not just exceptions: a TypeError in one component's shutdown is the same class of
	 * problem and must not abort the walk either.
	 */
	public function testShutdownAllContinuesPastAThrownError(): void
	{
		$sequence = new ShutdownSequence();
		$log = new ShutdownLog();
		$sequence->replaceAll([
			new ThrowingShutdownSequenceTestComponent(new \TypeError('bad type')),
			new ShutdownSequenceTestComponent('after', $log),
		]);

		$sequence->shutdownAll();

		$this->assertSame(['after'], $log->entries());
	}

	public function testShutdownAllOnAnEmptySequenceIsANoOp(): void
	{
		(new ShutdownSequence())->shutdownAll();

		$this->addToAssertionCount(1);
	}
}

/** Records the order components were shut down in. */
class ShutdownLog
{
	/** @var array<int, string> */
	private array $entries = [];

	public function record(string $label): void
	{
		$this->entries[] = $label;
	}

	/** @return array<int, string> */
	public function entries(): array
	{
		return $this->entries;
	}
}

class ShutdownSequenceTestComponent
{
	public function __construct(
		public readonly string $label,
		private readonly ?ShutdownLog $log = null,
	) {
	}

	public function shutdown(): void
	{
		$this->log?->record($this->label);
	}
}

class ThrowingShutdownSequenceTestComponent
{
	public function __construct(private readonly \Throwable $toThrow)
	{
	}

	public function shutdown(): void
	{
		throw $this->toThrow;
	}
}
