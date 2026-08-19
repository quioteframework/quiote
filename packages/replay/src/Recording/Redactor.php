<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use Stringable;

/**
 * Header/cookie/param/session/body scrubbing per `docs/RECORD_REPLAY_PLAN.md`
 * §11. Applied at capture time, inside {@see RecordingSession}'s record
 * methods -- never deferred to serialization, so a denied value never enters
 * process memory in an unredacted form that a later dump could leak.
 *
 * Cookie names are matched against the same denylist as params: a cookie
 * carrying a session/auth token (`token`, `secret`, ...) is exactly as
 * sensitive as a request parameter by the same name, and the config surface
 * (`docs/RECORD_REPLAY_PLAN.md` §10) has no separate `replay.redact.cookies`
 * key.
 */
final readonly class Redactor
{
    /**
     * @param list<string> $deniedHeaders lower-cased header names
     * @param list<string> $deniedParams lower-cased param/cookie field names
     * @param list<string> $deniedSessionKeys lower-cased session key names
     */
    public function __construct(
        private array $deniedHeaders,
        private array $deniedParams,
        private array $deniedSessionKeys,
        private RedactionMode $mode = RedactionMode::Drop,
    ) {
    }

    /**
     * @param array<string, array<string>> $headers
     * @return array<string, array<string>>
     */
    public function redactHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $values) {
            $result[$name] = in_array(strtolower($name), $this->deniedHeaders, true)
                ? array_map($this->apply(...), $values)
                : $values;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $cookies
     * @return array<string, mixed>
     */
    public function redactCookies(array $cookies): array
    {
        return $this->redactFlat($cookies, $this->deniedParams);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function redactSession(array $session): array
    {
        return $this->redactFlat($session, $this->deniedSessionKeys);
    }

    /**
     * Redacts a params/body structure, descending into nested arrays so a
     * denied field name is caught regardless of nesting depth.
     *
     * @param array<array-key, mixed> $params
     * @return array<array-key, mixed>
     */
    public function redactParams(array $params): array
    {
        return $this->redactNested($params);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $denylist
     * @return array<array-key, mixed>
     */
    private function redactFlat(array $data, array $denylist): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = (is_string($key) && in_array(strtolower($key), $denylist, true))
                ? $this->apply($value)
                : $value;
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function redactNested(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $this->deniedParams, true)) {
                $result[$key] = $this->apply($value);
                continue;
            }
            $result[$key] = is_array($value) ? $this->redactNested($value) : $value;
        }

        return $result;
    }

    private function apply(mixed $value): string
    {
        if ($this->mode === RedactionMode::Drop) {
            return '[REDACTED]';
        }

        $stringValue = $this->stringify($value);
        if ($stringValue === null) {
            return '[REDACTED]';
        }

        return $this->mode === RedactionMode::Hash
            ? 'sha256:' . hash('sha256', $stringValue)
            : self::mask($stringValue);
    }

    private function stringify(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        if ($value instanceof Stringable) {
            return (string)$value;
        }

        return null;
    }

    private static function mask(string $value): string
    {
        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($value, -4);
    }
}
