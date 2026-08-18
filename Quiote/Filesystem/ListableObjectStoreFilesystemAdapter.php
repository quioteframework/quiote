<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

use Quiote\Storage\ListableObjectStoreClientInterface;
use Quiote\Storage\ObjectStoreException;

/**
 * A {@see ListableFilesystemInterface} over any {@see ListableObjectStoreClientInterface},
 * everything but listing inherited unchanged from {@see ObjectStoreFilesystemAdapter}.
 *
 * {@see listContents()} treats $path the way a filesystem does even though the store underneath
 * is flat: it lists with a `/` delimiter at the key one level below $path, so a deeper key never
 * surfaces as if it were a direct child, then folds the store's own pagination away -- a caller
 * gets one full, sorted list rather than having to drive continuation tokens itself. A prefix
 * that groups into a "directory" comes back as a bare relative path, exactly like a subdirectory
 * from {@see LocalFilesystemAdapter::listContents()}, not with its trailing delimiter.
 *
 * A second, separately-typed reference to the same client this class was constructed with:
 * {@see ObjectStoreFilesystemAdapter::$client} cannot be redeclared with a narrower type in PHP
 * (property types are invariant across inheritance), so the listing-capable view of the identical
 * object lives here instead.
 *
 * @since      4.2.0
 */
readonly class ListableObjectStoreFilesystemAdapter extends ObjectStoreFilesystemAdapter implements ListableFilesystemInterface
{
    private const int PAGE_SIZE = 1000;

    public function __construct(
        private ListableObjectStoreClientInterface $listableClient,
        string $providerName,
        string $keyPrefix = '',
    ) {
        parent::__construct($listableClient, $providerName, $keyPrefix);
    }

    /**
     * {@inheritDoc}
     *
     * @throws     FilesystemStorageException If the store call itself failed.
     */
    #[\Override]
    public function listContents(string $path = ''): array
    {
        $prefix = $this->key($path === '' ? '' : rtrim($path, '/') . '/');
        $entries = [];
        $continuationToken = null;

        do {
            try {
                $page = $this->listableClient->listObjects($prefix, '/', $continuationToken, self::PAGE_SIZE);
            } catch (ObjectStoreException $e) {
                throw new FilesystemStorageException(sprintf('Failed listing directory "%s": %s', $path, $e->getMessage()), 0, $e);
            }

            foreach ($page->objects as $object) {
                $entries[] = substr($object->key, strlen($prefix));
            }
            foreach ($page->commonPrefixes as $commonPrefix) {
                $entries[] = rtrim(substr($commonPrefix, strlen($prefix)), '/');
            }

            $continuationToken = $page->nextContinuationToken;
        } while ($continuationToken !== null);

        sort($entries);

        return $entries;
    }
}
