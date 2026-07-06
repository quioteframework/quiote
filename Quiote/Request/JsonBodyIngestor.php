<?php

declare(strict_types=1);

namespace Quiote\Request;

use Psr\Http\Message\UploadedFileInterface;
use Quiote\Exception\QuioteException;

/**
 * Detects application/json payloads sent through Quiote write/update requests
 * and decodes them into a flat parameter map for WebRequest to merge into its
 * runtime parameter store.
 */
final class JsonBodyIngestor
{
    private function __construct()
    {
    }

    /**
     * $putFileProvider and $rawInputProvider are only invoked when the method
     * and content-type actually indicate a JSON write/update payload, so
     * callers can pass expensive lookups (php://input, uploaded file streams)
     * without paying for them on every request.
     * @param \Closure(): ?UploadedFileInterface $putFileProvider
     * @param \Closure(): string $rawInputProvider
     * @return array<int|string, mixed> Decoded fields to import, or [] when
     *         this request is not a JSON write/update payload.
     */
    public static function ingest(
        string $method,
        string $contentType,
        \Closure $putFileProvider,
        \Closure $rawInputProvider,
    ): array {
        $method = strtolower($method);
        if ($method !== 'write' && $method !== 'update') {
            return [];
        }

        if ($contentType === '') {
            return [];
        }

        if (!preg_match('#^application/json(;[^;]+)*?$#i', $contentType)) {
            return [];
        }

        $jsonString = '';
        if ($method === 'update') {
            $putFile = $putFileProvider();
            if ($putFile === null) {
                throw new QuioteException('Missing PUT payload upload');
            }
            $jsonString = (string)$putFile->getStream()->getContents();
        } else {
            $jsonString = $rawInputProvider();
        }

        if ($jsonString === '') {
            throw new QuioteException('Empty request body');
        }

        $data = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new QuioteException('Invalid JSON payload: ' . json_last_error_msg());
        }

        return (is_array($data) && $data !== []) ? $data : [];
    }
}
