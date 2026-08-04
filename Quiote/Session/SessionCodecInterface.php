<?php

declare(strict_types=1);

namespace Quiote\Session;

/**
 * Serializes a session payload for storage, and reads it back.
 *
 * A session's stored form is a wire format: whatever writes it has to agree with whatever reads
 * it, including a different backend reading a payload another one wrote, and a build with
 * different extensions available. That agreement is this interface's whole purpose -- a
 * {@see SessionPersistenceInterface} implementation decides *where* a payload goes and delegates
 * *what it looks like* here.
 *
 * Implement this to change the stored form -- encryption at rest, a compressed envelope, a
 * format an external consumer already reads -- and hand it to the persistence backend.
 *
 * @since      3.2.0
 */
interface SessionCodecInterface
{
    /**
     * Encode session data for storage.
     *
     * @param      array<string, mixed> $data
     * @throws     \Quiote\Exception\StorageException If the data cannot be encoded at all.
     */
    public function encode(array $data): string;

    /**
     * Decode a stored payload, or null when it does not hold readable session data.
     *
     * Null rather than an exception for unreadable input: a payload written by an older format,
     * a truncated row, or a value that decodes to something that is not a session are all
     * reasons to treat the session as absent and start a new one, not to fail the request.
     *
     * @return     array<string, mixed>|null
     */
    public function decode(string $payload): ?array;
}
