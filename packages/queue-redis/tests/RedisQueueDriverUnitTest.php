<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Queue\JobPayload;
use Quiote\Queue\Redis\RedisQueueDriver;
use Quiote\Queue\ReservedJob;
use Quiote\Test\Redis\InMemoryPredisClient;

/**
 * RedisQueueDriver against an in-memory Predis client: key layout, the
 * reliable-queue handover between the ready and processing lists, delayed-job
 * promotion and the malformed-entry guards. RedisQueueDriverTest covers the
 * same driver against a container and is #[Group('integration')].
 */
final class RedisQueueDriverUnitTest extends TestCase
{
    private const PREFIX = 'test_queue';

    private InMemoryPredisClient $redis;

    private RedisQueueDriver $driver;

    #[\Override]
    protected function setUp(): void
    {
        $this->redis = new InMemoryPredisClient();
        $this->driver = new RedisQueueDriver($this->redis, self::PREFIX);
    }

    /**
     * Files a raw entry on the delayed set with a score already in the past,
     * standing in for a job whose delay has elapsed since it was pushed.
     */
    private function seedDueDelayedEntry(string $jobClass, int $secondsOverdue = 5): string
    {
        $entry = json_encode([
            'uid' => 'seeded-uid',
            'job_class' => $jobClass,
            'params' => ['k' => 'v'],
            'attempts' => 0,
            'available_at' => time() - $secondsOverdue,
        ], JSON_THROW_ON_ERROR);

        $this->redis->zadd(self::PREFIX . ':delayed', [$entry => time() - $secondsOverdue]);

        return $entry;
    }

    /** @return array<string, mixed> */
    private function decode(string $entry): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($entry, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testReserveOnAnEmptyQueueReturnsNull(): void
    {
        $this->assertNull($this->driver->reserve());
    }

    public function testAPushedJobIsReservableWithItsPayloadIntact(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\SendMail', ['to' => 'a@example.com'], 2));

        $reserved = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $reserved);
        $this->assertSame('App\\Job\\SendMail', $reserved->payload->jobClass);
        $this->assertSame(['to' => 'a@example.com'], $reserved->payload->params);
        $this->assertSame(2, $reserved->payload->attempts);
    }

    public function testAnImmediateJobLandsOnTheReadyList(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Now'));

        $this->assertSame(1, $this->redis->llen(self::PREFIX . ':ready'));
        $this->assertSame(0, $this->redis->zcard(self::PREFIX . ':delayed'));
    }

    public function testAJobDatedInThePastCountsAsImmediatelyDue(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Overdue', availableAt: new DateTimeImmutable('-1 hour')));

        $this->assertSame(1, $this->redis->llen(self::PREFIX . ':ready'));
    }

    public function testAFutureDatedJobLandsOnTheDelayedSetAndIsNotReservable(): void
    {
        $availableAt = new DateTimeImmutable('+1 hour');
        $this->driver->push(new JobPayload('App\\Job\\Later', availableAt: $availableAt));

        $this->assertSame(0, $this->redis->llen(self::PREFIX . ':ready'));
        $this->assertSame(1, $this->redis->zcard(self::PREFIX . ':delayed'));
        $this->assertNull($this->driver->reserve());
    }

    public function testADelayedJobIsScoredByItsAvailabilityTimestamp(): void
    {
        $availableAt = new DateTimeImmutable('+1 hour');
        $this->driver->push(new JobPayload('App\\Job\\Later', availableAt: $availableAt));

        $entries = $this->redis->zrangebyscore(self::PREFIX . ':delayed', '-inf', '+inf');
        $this->assertCount(1, $entries);
        $this->assertSame(
            (float) $availableAt->getTimestamp(),
            $this->redis->zscore(self::PREFIX . ':delayed', $entries[0]),
        );
    }

    public function testReservePromotesADueDelayedJobOntoTheReadyList(): void
    {
        $this->seedDueDelayedEntry('App\\Job\\WasDelayed');

        $reserved = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $reserved);
        $this->assertSame('App\\Job\\WasDelayed', $reserved->payload->jobClass);
        $this->assertSame(0, $this->redis->zcard(self::PREFIX . ':delayed'), 'promoted entries leave the delayed set');
    }

    public function testPromotionLeavesJobsThatAreStillNotDueAlone(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Later', availableAt: new DateTimeImmutable('+1 hour')));
        $this->seedDueDelayedEntry('App\\Job\\Due');

        $reserved = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $reserved);
        $this->assertSame('App\\Job\\Due', $reserved->payload->jobClass);
        $this->assertSame(1, $this->redis->zcard(self::PREFIX . ':delayed'), 'the not-yet-due job stays put');
    }

    public function testAReservedJobMovesToTheProcessingListSoACrashCanRecoverIt(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\InFlight'));

        $reserved = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $reserved);
        $this->assertSame(0, $this->redis->llen(self::PREFIX . ':ready'));
        $this->assertSame([$reserved->id], $this->redis->lrange(self::PREFIX . ':processing', 0, -1));
    }

    public function testTheReservationIdIsTheStoredEntryItself(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\One'));

        $reserved = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $reserved);
        $this->assertSame('App\\Job\\One', $this->decode($reserved->id)['job_class']);
    }

    public function testJobsAreReservedInTheOrderTheyWerePushed(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\First'));
        $this->driver->push(new JobPayload('App\\Job\\Second'));

        $first = $this->driver->reserve();
        $second = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $first);
        $this->assertInstanceOf(ReservedJob::class, $second);
        $this->assertSame('App\\Job\\First', $first->payload->jobClass);
        $this->assertSame('App\\Job\\Second', $second->payload->jobClass);
    }

    /**
     * Two jobs that are identical field-for-field still have to be removable
     * one at a time, which is what the per-entry `uid` is for -- LREM matches
     * on the whole string.
     */
    public function testTwoIdenticalJobsStayDistinctListMembers(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Same', ['x' => 1]));
        $this->driver->push(new JobPayload('App\\Job\\Same', ['x' => 1]));

        $first = $this->driver->reserve();
        $second = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $first);
        $this->assertInstanceOf(ReservedJob::class, $second);
        $this->assertNotSame($first->id, $second->id);

        $this->driver->ack($first);

        $this->assertSame([$second->id], $this->redis->lrange(self::PREFIX . ':processing', 0, -1));
    }

    public function testAckClearsTheJobFromTheProcessingList(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Done'));
        $reserved = $this->driver->reserve();
        $this->assertInstanceOf(ReservedJob::class, $reserved);

        $this->driver->ack($reserved);

        $this->assertSame(0, $this->redis->llen(self::PREFIX . ':processing'));
        $this->assertNull($this->driver->reserve());
    }

    public function testReleaseWithoutDelayPutsTheJobBackOnTheReadyList(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Retry', ['x' => 1]));
        $reserved = $this->driver->reserve();
        $this->assertInstanceOf(ReservedJob::class, $reserved);

        $this->driver->release($reserved, 0);

        $this->assertSame(0, $this->redis->llen(self::PREFIX . ':processing'));
        $this->assertSame(1, $this->redis->llen(self::PREFIX . ':ready'));
    }

    public function testAReleasedJobComesBackWithAnIncrementedAttemptCount(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Retry', ['x' => 1]));
        $reserved = $this->driver->reserve();
        $this->assertInstanceOf(ReservedJob::class, $reserved);

        $this->driver->release($reserved, 0);
        $again = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $again);
        $this->assertSame(1, $again->payload->attempts);
        $this->assertSame(['x' => 1], $again->payload->params);
    }

    public function testAReleasedJobKeepsItsOriginalUid(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Retry'));
        $reserved = $this->driver->reserve();
        $this->assertInstanceOf(ReservedJob::class, $reserved);
        $uid = $this->decode($reserved->id)['uid'];

        $this->driver->release($reserved, 0);
        $again = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $again);
        $this->assertSame($uid, $this->decode($again->id)['uid']);
    }

    public function testReleaseWithADelayParksTheJobOnTheDelayedSet(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Backoff'));
        $reserved = $this->driver->reserve();
        $this->assertInstanceOf(ReservedJob::class, $reserved);

        $this->driver->release($reserved, 300);

        $this->assertSame(0, $this->redis->llen(self::PREFIX . ':ready'));
        $this->assertSame(1, $this->redis->zcard(self::PREFIX . ':delayed'));
        $this->assertNull($this->driver->reserve());
    }

    public function testANegativeReleaseDelayIsTreatedAsImmediate(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Backoff'));
        $reserved = $this->driver->reserve();
        $this->assertInstanceOf(ReservedJob::class, $reserved);

        $this->driver->release($reserved, -60);

        $this->assertSame(1, $this->redis->llen(self::PREFIX . ':ready'));
        $this->assertSame(0, $this->redis->zcard(self::PREFIX . ':delayed'));
    }

    public function testDiscardDropsTheJobWithoutRequeueingIt(): void
    {
        $this->driver->push(new JobPayload('App\\Job\\Dead'));
        $reserved = $this->driver->reserve();
        $this->assertInstanceOf(ReservedJob::class, $reserved);

        $this->driver->discard($reserved);

        $this->assertSame(0, $this->redis->llen(self::PREFIX . ':processing'));
        $this->assertSame(0, $this->redis->llen(self::PREFIX . ':ready'));
        $this->assertSame(0, $this->redis->zcard(self::PREFIX . ':delayed'));
    }

    public function testTwoDriversOnDifferentPrefixesDoNotShareJobs(): void
    {
        $other = new RedisQueueDriver($this->redis, 'other_queue');
        $this->driver->push(new JobPayload('App\\Job\\Mine'));

        $this->assertNull($other->reserve());
        $this->assertInstanceOf(ReservedJob::class, $this->driver->reserve());
    }

    public function testIntegerKeyedParamsComeBackStringKeyed(): void
    {
        $entry = json_encode([
            'uid' => 'u',
            'job_class' => 'App\\Job\\Listy',
            'params' => ['a', 'b'],
            'attempts' => 0,
            'available_at' => time(),
        ], JSON_THROW_ON_ERROR);
        $this->redis->lpush(self::PREFIX . ':ready', [$entry]);

        $reserved = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $reserved);
        $this->assertSame(['0' => 'a', '1' => 'b'], $reserved->payload->params);
    }

    public function testAnEntryThatIsNotAJsonObjectIsRejected(): void
    {
        $this->redis->lpush(self::PREFIX . ':ready', ['"just a string"']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a JSON object');

        $this->driver->reserve();
    }

    public function testAnEntryWithoutAJobClassIsRejected(): void
    {
        $this->redis->lpush(self::PREFIX . ':ready', ['{"uid":"u","params":{},"attempts":0}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-string "job_class"');

        $this->driver->reserve();
    }

    public function testAnEntryWithNonArrayParamsIsRejected(): void
    {
        $this->redis->lpush(self::PREFIX . ':ready', ['{"uid":"u","job_class":"A","params":"nope","attempts":0}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-array "params"');

        $this->driver->reserve();
    }

    public function testAnEntryWithNonIntAttemptsIsRejected(): void
    {
        $this->redis->lpush(self::PREFIX . ':ready', ['{"uid":"u","job_class":"A","params":{},"attempts":"many"}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-int "attempts"');

        $this->driver->reserve();
    }

    public function testAnEntryThatIsNotValidJsonIsRejected(): void
    {
        $this->redis->lpush(self::PREFIX . ':ready', ['{not json']);

        $this->expectException(JsonException::class);

        $this->driver->reserve();
    }

    /**
     * Missing `attempts` defaults to 0 rather than failing: an entry written
     * by an older driver version still has to run.
     */
    public function testAnEntryWithoutAttemptsDefaultsToZero(): void
    {
        $this->redis->lpush(self::PREFIX . ':ready', ['{"uid":"u","job_class":"App\\\\Job\\\\Old"}']);

        $reserved = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $reserved);
        $this->assertSame(0, $reserved->payload->attempts);
        $this->assertSame([], $reserved->payload->params);
    }

    /**
     * `release()` re-encodes from a reservation id it was handed; a caller
     * that passes an id the driver never issued must not be able to make it
     * emit a job with a copied uid.
     */
    public function testReleasingAnIdWithoutAUidStillProducesAReadableEntry(): void
    {
        $reserved = new ReservedJob('{"job_class":"A"}', new JobPayload('App\\Job\\Hand'));

        $this->driver->release($reserved, 0);
        $again = $this->driver->reserve();

        $this->assertInstanceOf(ReservedJob::class, $again);
        $this->assertSame('App\\Job\\Hand', $again->payload->jobClass);
        $this->assertSame(1, $again->payload->attempts);
        $this->assertNotSame('', $this->decode($again->id)['uid']);
    }
}
