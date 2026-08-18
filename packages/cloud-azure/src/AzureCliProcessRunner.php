<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * Runs one command and returns its standard output, so {@see AzureCliTokenProvider} can be
 * exercised in tests without actually shelling out to `az`.
 */
interface AzureCliProcessRunner
{
    /**
     * @param  list<string> $command Argv, never passed through a shell.
     * @throws AzureStorageException If the process could not be started or exited non-zero.
     */
    public function run(array $command): string;
}
