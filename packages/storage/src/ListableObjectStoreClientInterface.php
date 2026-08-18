<?php

declare(strict_types=1);

namespace Quiote\Storage;

/**
 * An {@see ObjectStoreClientInterface} whose store can also enumerate what it holds.
 *
 * Separate from the base contract for the same reason {@see \Quiote\Filesystem\ListableFilesystemInterface}
 * is separate from {@see \Quiote\Filesystem\FilesystemAdapterInterface}: a consumer that only
 * needs get/put/delete/head should not have to know whether the store behind the interface can
 * list, and one that does need listing should fail to wire up rather than fail at first call.
 *
 * Pagination, prefix/delimiter grouping and per-entry metadata are normalized the same way across
 * providers even though S3 (an opaque continuation token), GCS and Azure (both a marker, echoed
 * back as {@see ObjectListing::$nextContinuationToken}) each name and shape it differently on the
 * wire.
 *
 * @since      4.2.0
 */
interface ListableObjectStoreClientInterface extends ObjectStoreClientInterface
{
    /**
     * Lists up to $maxKeys keys starting with $prefix, oldest API quirks aside the same one page
     * at a time on every provider.
     *
     * With $delimiter empty, every matching key comes back as an {@see ObjectSummary} in
     * {@see ObjectListing::$objects} -- a fully recursive listing. With $delimiter set, a key is
     * only listed that way when $prefix (plus nothing else) reaches it before the first
     * occurrence of $delimiter; everything past that point is collapsed into one entry per
     * distinct prefix-up-to-and-including-the-delimiter in {@see ObjectListing::$commonPrefixes}
     * instead -- the "one directory level" view every provider's own console uses.
     *
     * $continuationToken must be null on the first call and, for a truncated result,
     * {@see ObjectListing::$nextContinuationToken} verbatim on the next -- it is opaque, provider
     * specific, and never meant to be inspected or constructed by a caller.
     *
     * @throws     ObjectStoreException On a transport or provider failure.
     */
    public function listObjects(string $prefix = '', string $delimiter = '', ?string $continuationToken = null, int $maxKeys = 1000): ObjectListing;
}
