<?php

declare(strict_types=1);

namespace Quiote\Storage\Gcs;

use Quiote\Session\ObjectStoreSessionPersistence;
use Quiote\Session\SessionCodecInterface;

/**
 * {@see \Quiote\Session\SessionPersistenceInterface} storing one JSON object per session id
 * (object `<prefix><sid>.json`) in a single GCS bucket.
 *
 * The storage behaviour is {@see ObjectStoreSessionPersistence}, shared with the other
 * object-store session backends; this class supplies the client.
 */
final class GcsSessionPersistence extends ObjectStoreSessionPersistence
{
    public function __construct(
        GcsClient $client,
        string $objectPrefix = 'sessions/',
        ?SessionCodecInterface $codec = null,
    ) {
        parent::__construct(
            $client,
            $objectPrefix,
            '.json',
            $codec ?? new \Quiote\Session\SessionCodec(preferBinary: false),
        );
    }
}
