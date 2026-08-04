<?php

declare(strict_types=1);

namespace Quiote\Storage\Gcs;

/**
 * A failure talking to GCS storage.
 *
 * Narrows {@see \Quiote\Storage\ObjectStoreException} to this provider, so a caller working
 * against {@see \Quiote\Storage\ObjectStoreClientInterface} can catch the supertype while one
 * that knows it is on GCS can still catch this.
 */
final class GcsStorageException extends \Quiote\Storage\ObjectStoreException
{
}
