<?php

declare(strict_types=1);

namespace Quiote\Replay\Store\Azure\Index;

use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Index\CassetteIndexException;
use Quiote\Replay\Index\CassetteIndexInterface;
use Quiote\Replay\Index\IndexHints;
use Quiote\Storage\Azure\AzureMonitorQueryClientInterface;
use Quiote\Storage\ObjectStoreClientInterface;

/**
 * Upgrades resolution from "an id plus a date/hour hint" to a bare id with nothing else: queries
 * the workspace for the pointer log line the recorder itself wrote, reads its `cassette_key`
 * straight off that record, and fetches the object at that key. Declines (returns null) when no
 * workspace is configured, or the query legitimately returns no matching pointer -- both are
 * "nothing to find here", not a broken index. A pointer that *is* found but whose object has since
 * expired throws instead of declining: the pointer outliving the cassette is a designed property
 * (a lifecycle rule can prune the blob long before log retention expires), and "the request failed
 * and a cassette existed for it, but it is gone now" is a materially different, more useful answer
 * than a plain "not found" -- worth surfacing, not swallowing into a decline.
 */
final readonly class LogAnalyticsIndex implements CassetteIndexInterface
{
    public function __construct(
        private ?AzureMonitorQueryClientInterface $queryClient,
        private ObjectStoreClientInterface $objectClient,
        private int $lookbackHours = 720,
        private CassetteCodec $codec = new CassetteCodec(),
    ) {
    }

    #[\Override]
    public function resolve(CassetteId $id, IndexHints $hints): ?Cassette
    {
        if ($this->queryClient === null) {
            return null;
        }

        $rows = $this->queryClient->query($this->buildKql($id));
        $key = $rows[0]['cassette_key'] ?? null;
        if (!is_string($key) || $key === '') {
            return null;
        }

        $blob = $this->objectClient->get($key);
        if ($blob === null) {
            throw new CassetteIndexException(sprintf(
                'Found a pointer log line for cassette "%s" naming key "%s", but no object exists there anymore -- '
                . 'it has most likely already been pruned by a retention/lifecycle rule.',
                $id->raw,
                $key,
            ));
        }

        return $this->codec->decode($blob);
    }

    private function buildKql(CassetteId $id): string
    {
        $safeId = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $id->raw);

        return sprintf(
            'ContainerLogV2'
            . ' | where TimeGenerated > ago(%dh)'
            . ' | where LogMessage.src == "app" and tostring(LogMessage.cassette_id) == "%s"'
            . ' | project cassette_key = tostring(LogMessage.cassette_key), TimeGenerated'
            . ' | where isnotempty(cassette_key)'
            . ' | top 1 by TimeGenerated desc',
            $this->lookbackHours,
            $safeId,
        );
    }
}
