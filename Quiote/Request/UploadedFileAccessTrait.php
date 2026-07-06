<?php

declare(strict_types=1);

namespace Quiote\Request;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Convenience accessors returning flat lists of UploadedFileInterface
 * instances, hiding PSR-7's nested-array upload structure from callers.
 */
trait UploadedFileAccessTrait
{
    /**
     * @return UploadedFileInterface[]
     */
    public function getUploadedFileArray(string $name): array
    {
        $uploadedFiles = $this->getUploadedFiles();
        if ($uploadedFiles === []) {
            return [];
        }

        return $this->flattenUploadedFiles($uploadedFiles[$name] ?? null);
    }

    /**
     * Convenience alias for getUploadedFileArray — returns PSR-7 UploadedFileInterface array.
     */
    public function getFile(string $name, mixed $default = null): mixed
    {
        $files = $this->getUploadedFileArray($name);
        return $files ?: $default;
    }

    /**
     * Return the first uploaded file for a given field name or null if none exist.
     */
    public function getUploadedFile(string $name): ?UploadedFileInterface
    {
        $files = $this->getUploadedFileArray($name);
        return $files[0] ?? null;
    }

    /**
     * Recursively flatten nested PSR-7 upload structures into a simple list.
     * @return UploadedFileInterface[]
     */
    private function flattenUploadedFiles(mixed $value): array
    {
        if ($value instanceof UploadedFileInterface) {
            return [$value];
        }

        if (!is_array($value) || $value === []) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            foreach ($this->flattenUploadedFiles($entry) as $file) {
                $normalized[] = $file;
            }
        }

        return $normalized;
    }
}
