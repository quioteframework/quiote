<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

use Quiote\Session\ObjectStoreSessionPersistence;
use Quiote\Session\SessionCodecInterface;

/**
 * {@see \Quiote\Session\SessionPersistenceInterface} storing one JSON blob per session id
 * (named `<sid>.json`) in a single Azure Blob container.
 *
 * Azure takes the container per call, so the client is wrapped in an
 * {@see AzureBlobContainerClient} that binds it and creates it on first write. Everything after
 * that is the shared behaviour in {@see ObjectStoreSessionPersistence}.
 */
final class AzureBlobSessionPersistence extends ObjectStoreSessionPersistence
{
    public function __construct(
        AzureBlobClient $client,
        string $container = 'quiote-sessions',
        ?SessionCodecInterface $codec = null,
    ) {
        parent::__construct(
            new AzureBlobContainerClient($client, $container),
            '',
            '.json',
            $codec ?? new \Quiote\Session\SessionCodec(preferBinary: false),
        );
    }
}
