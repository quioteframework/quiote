<?php

declare(strict_types=1);

namespace Quiote\Runtime;

use Quiote\Config\Config;
use Quiote\Logging\Log;
use RuntimeException;

/**
 * Catches anything the application echoes outside the response body while a
 * runtime with no SAPI output channel is handling the request.
 *
 * Under a SAPI, `echo` from a template or a stray debug statement lands in the
 * response and nobody notices. Under RoadRunner it lands on the process's
 * stdout, which is the relay RoadRunner speaks its protocol over, and under
 * Swoole it goes to the server's console. Both are worse than losing the
 * output, so the loop wraps the pipeline in a buffer and applies a policy.
 *
 * `core.worker.stray_output`:
 *  - `append`  (default) fold it onto the response body, matching what a SAPI
 *              would have produced
 *  - `discard` drop it, with a log line naming the size
 *  - `throw`   fail loudly, for development
 */
final class OutputCapture
{
    public const POLICY_APPEND = 'append';
    public const POLICY_DISCARD = 'discard';
    public const POLICY_THROW = 'throw';

    private int $baseLevel = 0;
    private bool $active = false;

    public function __construct(private readonly ?string $policy = null)
    {
    }

    public function start(): void
    {
        if ($this->active) {
            return;
        }
        $this->baseLevel = ob_get_level();
        ob_start();
        $this->active = true;
    }

    /**
     * Unwinds every buffer opened since start() -- application code may have
     * opened its own and not closed it, e.g. a renderer that threw mid-render --
     * and returns whatever was written, or '' when there was nothing.
     *
     * ob_get_clean() pops the innermost buffer first, so the collected chunks
     * come back inside-out and have to be reversed to reproduce write order.
     */
    public function finish(): string
    {
        if (!$this->active) {
            return '';
        }
        $this->active = false;

        $chunks = [];
        while (ob_get_level() > $this->baseLevel) {
            $chunk = ob_get_clean();
            $chunks[] = $chunk === false ? '' : $chunk;
        }

        return implode('', array_reverse($chunks));
    }

    /**
     * Applies the configured policy, returning the text to append to the
     * response body ('' when there is nothing to append).
     *
     * @throws RuntimeException when the policy is `throw` and output was captured.
     */
    public function apply(string $stray): string
    {
        if ($stray === '') {
            return '';
        }

        return match ($this->resolvePolicy()) {
            self::POLICY_DISCARD => $this->discard($stray),
            self::POLICY_THROW => throw new RuntimeException(sprintf(
                'The application wrote %d byte(s) outside the response body. The current worker runtime has no '
                . 'SAPI output channel, so this output cannot reach the client. Remove the echo/print, or set '
                . '"core.worker.stray_output" to "append" or "discard". Captured: %s',
                strlen($stray),
                self::excerpt($stray),
            )),
            default => $stray,
        };
    }

    private function discard(string $stray): string
    {
        Log::for($this)->notice(sprintf(
            '[OutputCapture] discarded %d byte(s) of stray output written outside the response body: %s',
            strlen($stray),
            self::excerpt($stray),
        ));
        return '';
    }

    private function resolvePolicy(): string
    {
        return $this->policy ?? Config::getString('core.worker.stray_output', self::POLICY_APPEND);
    }

    private static function excerpt(string $stray): string
    {
        $trimmed = trim($stray);
        return strlen($trimmed) > 200 ? substr($trimmed, 0, 200) . '...' : $trimmed;
    }
}
