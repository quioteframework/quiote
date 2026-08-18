<?php

declare(strict_types=1);

namespace Quiote\Storage;

/**
 * One page of {@see ListableObjectStoreClientInterface::listObjects()}.
 *
 * `$commonPrefixes` is only ever non-empty when the call passed a delimiter: it is the
 * "directories" one level below the prefix, the same grouping S3, GCS and Azure each fold keys
 * into when asked to stop at the first delimiter after the prefix. Without a delimiter every
 * matching key comes back in `$objects`, flat.
 *
 * @since      4.2.0
 */
final readonly class ObjectListing
{
    /**
     * @param list<ObjectSummary> $objects        In the order the provider returned them.
     * @param list<string>        $commonPrefixes Each ending in the delimiter that produced it.
     */
    public function __construct(
        public array $objects,
        public array $commonPrefixes,
        public ?string $nextContinuationToken,
    ) {
    }

    /** Whether another page follows -- pass {@see $nextContinuationToken} back in to fetch it. */
    public function isTruncated(): bool
    {
        return $this->nextContinuationToken !== null;
    }
}
