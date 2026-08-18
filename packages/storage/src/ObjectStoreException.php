<?php

declare(strict_types=1);

namespace Quiote\Storage;

use RuntimeException;

/**
 * A failure talking to an object store.
 *
 * The shared supertype of every provider's own storage exception, so code that works against
 * {@see ObjectStoreClientInterface} can catch one type instead of enumerating providers. Each
 * provider keeps its own subclass, so `catch (S3StorageException)` still narrows to S3 when that
 * distinction matters.
 *
 * @since      3.2.0
 */
class ObjectStoreException extends RuntimeException
{
}
