<?php

namespace Quiote\Support;

use Psr\Http\Message\ServerRequestInterface;
use Quiote\Support\Random\Randomness;

/**
 * Resolves a per-request correlation ID: adopt a sane inbound header value if
 * present, else generate a fresh one. Pure and dependency-free so it is unit
 * testable without a bootstrapped {@see \Quiote\Context}; the Context wires the
 * configured header name / expose flag around it.
 */
final class CorrelationId
{
    public const DEFAULT_HEADER = 'X-Correlation-Id';

    /** Max adopted length — the value becomes a log field and a response header. */
    private const MAX_LENGTH = 200;

    /**
     * The sanitized inbound correlation ID from $header, or null when absent or
     * empty after sanitization. The value is untrusted (it is echoed into a
     * response header and log lines), so control bytes — CR/LF included, the
     * header/log-injection vector — are stripped and the length is capped.
     */
    public static function fromRequest(ServerRequestInterface $request, string $header = self::DEFAULT_HEADER): ?string
    {
        return self::sanitize($request->getHeaderLine($header));
    }

    /** @internal exposed for testing the sanitization independently of PSR-7. */
    public static function sanitize(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $clean = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $raw) ?? '');
        if ($clean === '') {
            return null;
        }
        return mb_substr($clean, 0, self::MAX_LENGTH);
    }

    /**
     * A fresh high-entropy correlation ID (URL/log-safe), with a non-crypto
     * fallback.
     *
     * base64url, not `strtr($b64, '+/=', 'ABC')`: mapping the three non-alphanumeric
     * characters onto `A`/`B`/`C` collides them with genuine `A`/`B`/`C` output,
     * which throws away entropy for no reason -- and it consumed the padding
     * itself, leaving the following `rtrim($x, '=')` with nothing to strip and
     * literal `C`s on the end of every id.
     */
    public static function generate(): string
    {
        try {
            return rtrim(strtr(base64_encode(Randomness::instance()->bytes(10)), '+/', '-_'), '=');
        } catch (\Throwable) {
            return uniqid('req', true);
        }
    }
}
