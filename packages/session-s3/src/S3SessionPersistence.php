<?php

declare(strict_types=1);

namespace Quiote\Storage\S3;

use Quiote\Session\ObjectStoreSessionPersistence;
use Quiote\Session\SessionCodecInterface;

/**
 * {@see \Quiote\Session\SessionPersistenceInterface} storing one JSON object per session id
 * (key `<prefix><sid>.json`) in a single S3 bucket.
 *
 * The storage behaviour is {@see ObjectStoreSessionPersistence}, shared with the other
 * object-store session backends; this class supplies the client.
 */
final class S3SessionPersistence extends ObjectStoreSessionPersistence
{
    public function __construct(
        S3Client $client,
        string $keyPrefix = 'sessions/',
        ?SessionCodecInterface $codec = null,
    ) {
        parent::__construct(
            $client,
            $keyPrefix,
            '.json',
            $codec ?? new \Quiote\Session\SessionCodec(preferBinary: false),
        );
    }
}
