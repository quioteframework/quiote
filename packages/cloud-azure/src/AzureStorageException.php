<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * A failure talking to Azure storage.
 *
 * Narrows {@see \Quiote\Storage\ObjectStoreException} to this provider, so a caller working
 * against {@see \Quiote\Storage\ObjectStoreClientInterface} can catch the supertype while one
 * that knows it is on Azure can still catch this.
 */
final class AzureStorageException extends \Quiote\Storage\ObjectStoreException
{
}
