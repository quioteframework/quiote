<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

/** Thrown by {@see FilesystemAdapterInterface::read()}/{@see FilesystemAdapterInterface::size()}/{@see FilesystemAdapterInterface::lastModified()} when the path does not exist. */
class FileNotFoundStorageException extends FilesystemStorageException
{
}
