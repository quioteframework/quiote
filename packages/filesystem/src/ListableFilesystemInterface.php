<?php

declare(strict_types=1);

namespace Quiote\Filesystem;

/**
 * A filesystem that can enumerate what it holds.
 *
 * Separate from {@see FilesystemAdapterInterface} because listing is the one operation a store
 * may genuinely not offer: the S3, GCS and Azure adapters are built on single-object REST calls
 * with no list endpoint, so they can read, write, delete, stat and test existence but cannot
 * enumerate. Declaring listContents() on the base contract and throwing from three of four
 * implementations made every consumer's type useless -- it could not tell whether the call would
 * work without knowing which adapter it actually held.
 *
 * Type-hint this where listing is required, and the wiring fails at the point a non-listable
 * driver is configured rather than at the point it is called. Same shape as
 * {@see \Quiote\Queue\PollableQueueDriverInterface}, for the same reason.
 *
 * @since      3.2.0
 */
interface ListableFilesystemInterface extends FilesystemAdapterInterface
{
    /**
     * The entries directly under $path.
     *
     * @return     list<string> Relative paths, non-recursive.
     */
    public function listContents(string $path = ''): array;
}
