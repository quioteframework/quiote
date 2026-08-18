<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Quiote\Queue\JobPayload;
use Quiote\Queue\QueueDriverInterface;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Queue\QueueFingerprint;
use Quiote\Replay\Queue\RecordingQueueDriver;

/**
 * The isolated-replay counterpart to
 * {@see \Quiote\Replay\Queue\RecordingQueueDriver}: never pushes to a real
 * backend -- isolated replay has none -- and instead captures every
 * {@see push()} call so an emitted test can assert against it afterward, per
 * the record/replay plan: "the emitted test can then assert 'this request
 * enqueued exactly this job'."
 *
 * Deliberately exposes a plain, non-throwing {@see wasJobPushed()} query and
 * a raw {@see pushedJobs()} accessor rather than a throwing `assert*()`
 * method of its own: making assertions is a test's job (via its own
 * `self::assertTrue()`/`self::assertSame()`), not this driver's -- it only
 * needs to answer "what was pushed", accurately and in order.
 *
 * Also appends to an (optional) {@see EffectLedger}, using the same
 * {@see QueueFingerprint} scheme {@see RecordingQueueDriver} recorded with,
 * so a push asked for during replay is comparable against what was
 * originally recorded -- e.g. a future drift report could show "this replay
 * enqueued a job the original recording did not, or vice versa." The ledger
 * is optional because, unlike a read-side stub, there is no recorded answer
 * this class needs the ledger for -- it works perfectly well with none.
 */
final class AssertingQueueDriver implements QueueDriverInterface
{
    /** @var list<JobPayload> */
    private array $pushed = [];

    public function __construct(private readonly ?EffectLedger $ledger = null)
    {
    }

    #[\Override]
    public function push(JobPayload $payload): void
    {
        $this->pushed[] = $payload;

        $this->ledger?->record(
            EffectKind::Queue,
            QueueFingerprint::ofPush($payload),
            RecordingQueueDriver::describe($payload),
            null,
        );
    }

    /** @return list<JobPayload> Every job pushed during this replay, in the order it was pushed. */
    public function pushedJobs(): array
    {
        return $this->pushed;
    }

    /**
     * Whether a job of $jobClass -- with $params too, when given -- was
     * pushed during this replay.
     *
     * @param array<string, mixed>|null $params
     */
    public function wasJobPushed(string $jobClass, ?array $params = null): bool
    {
        foreach ($this->pushed as $payload) {
            if ($payload->jobClass !== $jobClass) {
                continue;
            }
            if ($params !== null && $payload->params !== $params) {
                continue;
            }

            return true;
        }

        return false;
    }
}
