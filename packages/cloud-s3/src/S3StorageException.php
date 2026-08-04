<?php

declare(strict_types=1);

namespace Quiote\Storage\S3;

/**
 * A failure talking to S3 storage.
 *
 * Narrows {@see \Quiote\Storage\ObjectStoreException} to this provider, so a caller working
 * against {@see \Quiote\Storage\ObjectStoreClientInterface} can catch the supertype while one
 * that knows it is on S3 can still catch this.
 */
final class S3StorageException extends \Quiote\Storage\ObjectStoreException
{
}
