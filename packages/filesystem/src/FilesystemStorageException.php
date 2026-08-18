<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

use Quiote\Exception\StorageException;

/** Thrown when a {@see FilesystemAdapterInterface} operation fails. */
class FilesystemStorageException extends StorageException
{
}
