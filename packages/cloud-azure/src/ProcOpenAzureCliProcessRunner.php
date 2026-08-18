<?php

declare(strict_types=1);

namespace Quiote\Storage\Azure;

/**
 * Default {@see AzureCliProcessRunner}: runs the command directly via `proc_open()`'s array form,
 * never through a shell, so there is nothing for the fixed, argument-free `az` invocation to
 * inject into.
 */
final class ProcOpenAzureCliProcessRunner implements AzureCliProcessRunner
{
    /** @inheritDoc */
    #[\Override]
    public function run(array $command): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new AzureStorageException(sprintf('Could not start "%s".', $command[0] ?? ''));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new AzureStorageException(sprintf(
                '"%s" exited with status %d: %s',
                implode(' ', $command),
                $exitCode,
                trim((string) $stderr),
            ));
        }

        return (string) $stdout;
    }
}
