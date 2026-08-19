<?php

declare(strict_types=1);

namespace Quiote\Replay\Replay;

use Psr\Http\Message\ResponseInterface;
use Quiote\Support\Compiler\Diagnostic;

/**
 * Diffs a fresh replay response against a cassette's recorded one: drift as
 * a feature -- every difference is reported through {@see Diagnostic},
 * never silently smoothed over. No existing diffing helper exists anywhere
 * in the codebase for this; this is the first one.
 *
 * Status and body mismatches are {@see Diagnostic::SEVERITY_ERROR} (the
 * response a client would actually see changed); header differences are
 * {@see Diagnostic::SEVERITY_WARNING}, since a header set routinely
 * includes ambient values. A short, fixed denylist of headers that are
 * *expected* to differ on every single replay (`Date`, `Set-Cookie`, the
 * correlation-id headers) is skipped entirely rather than reported as
 * warning noise on every run.
 */
final class ResponseDiffer
{
    private const VOLATILE_HEADERS = ['date', 'set-cookie', 'x-correlation-id', 'x-request-id', 'x-quiote-rid'];

    /**
     * @param array<string, mixed> $recorded The cassette's `response` section.
     * @return list<Diagnostic>
     */
    public function diff(array $recorded, ResponseInterface $fresh, string $cassetteId): array
    {
        $diagnostics = [];

        $recordedStatus = $recorded['status'] ?? null;
        if (is_int($recordedStatus) && $recordedStatus !== $fresh->getStatusCode()) {
            $diagnostics[] = new Diagnostic(
                Diagnostic::SEVERITY_ERROR,
                'REPLAY_STATUS_MISMATCH',
                sprintf('Recorded status %d, replay returned %d.', $recordedStatus, $fresh->getStatusCode()),
                $cassetteId,
            );
        }

        $recordedHeaders = $recorded['headers'] ?? null;
        if (is_array($recordedHeaders)) {
            array_push($diagnostics, ...$this->diffHeaders($recordedHeaders, $fresh->getHeaders(), $cassetteId));
        }

        $bodyDiagnostic = $this->diffBody($recorded['body'] ?? null, (string)$fresh->getBody(), $cassetteId);
        if ($bodyDiagnostic !== null) {
            $diagnostics[] = $bodyDiagnostic;
        }

        return $diagnostics;
    }

    /**
     * @param array<array-key, mixed> $recorded
     * @param array<string, array<string>> $fresh
     * @return list<Diagnostic>
     */
    private function diffHeaders(array $recorded, array $fresh, string $cassetteId): array
    {
        $diagnostics = [];

        /** @var array<string, array{0: string, 1: array<string>}> $freshByLowerName */
        $freshByLowerName = [];
        foreach ($fresh as $name => $values) {
            $freshByLowerName[strtolower($name)] = [$name, $values];
        }

        foreach ($recorded as $name => $values) {
            if (!is_string($name) || in_array(strtolower($name), self::VOLATILE_HEADERS, true)) {
                continue;
            }
            $recordedValues = is_array($values) ? array_values($values) : [];
            $match = $freshByLowerName[strtolower($name)] ?? null;
            unset($freshByLowerName[strtolower($name)]);

            if ($match === null) {
                $diagnostics[] = new Diagnostic(
                    Diagnostic::SEVERITY_WARNING,
                    'REPLAY_HEADER_MISSING',
                    sprintf('Recorded header "%s" is absent from the replayed response.', $name),
                    $cassetteId,
                );
                continue;
            }
            if ($match[1] !== $recordedValues) {
                $diagnostics[] = new Diagnostic(
                    Diagnostic::SEVERITY_WARNING,
                    'REPLAY_HEADER_MISMATCH',
                    sprintf(
                        'Header "%s" changed: recorded %s, replayed %s.',
                        $name,
                        json_encode($recordedValues, JSON_UNESCAPED_SLASHES) ?: '[]',
                        json_encode($match[1], JSON_UNESCAPED_SLASHES) ?: '[]',
                    ),
                    $cassetteId,
                );
            }
        }

        foreach ($freshByLowerName as [$freshName, ]) {
            if (in_array(strtolower($freshName), self::VOLATILE_HEADERS, true)) {
                continue;
            }
            $diagnostics[] = new Diagnostic(
                Diagnostic::SEVERITY_WARNING,
                'REPLAY_HEADER_UNEXPECTED',
                sprintf('Header "%s" appeared in the replayed response but was not recorded.', $freshName),
                $cassetteId,
            );
        }

        return $diagnostics;
    }

    private function diffBody(mixed $recordedBody, string $freshBody, string $cassetteId): ?Diagnostic
    {
        if (!is_array($recordedBody)) {
            return null;
        }
        $content = $recordedBody['content'] ?? null;
        if (!is_string($content)) {
            return null;
        }
        // `!== false`, not `?:` -- the latter also swallows a body that legitimately decodes to
        // "0", and the two sibling decoders in RequestReconstructor and TestEmitter both get this
        // right.
        $decoded = $content;
        if (($recordedBody['encoding'] ?? 'utf8') === 'base64') {
            $raw = base64_decode($content, true);
            $decoded = $raw !== false ? $raw : '';
        }
        $truncated = (bool)($recordedBody['truncated'] ?? false);

        if ($truncated) {
            // Only the recorded prefix is known; a matching prefix is the strongest claim
            // that can honestly be made, not full equality.
            if (substr($freshBody, 0, strlen($decoded)) !== $decoded) {
                return new Diagnostic(
                    Diagnostic::SEVERITY_WARNING,
                    'REPLAY_BODY_MISMATCH',
                    'Replayed response body differs from the recorded (truncated) prefix.',
                    $cassetteId,
                );
            }

            return null;
        }

        if ($decoded !== $freshBody) {
            return new Diagnostic(
                Diagnostic::SEVERITY_ERROR,
                'REPLAY_BODY_MISMATCH',
                sprintf(
                    'Replayed response body differs from the recording (recorded sha256 %s, replayed %s).',
                    hash('sha256', $decoded),
                    hash('sha256', $freshBody),
                ),
                $cassetteId,
            );
        }

        return null;
    }
}
