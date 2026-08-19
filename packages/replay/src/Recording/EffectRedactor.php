<?php

declare(strict_types=1);

namespace Quiote\Replay\Recording;

use Quiote\Config\Config;
use Quiote\Replay\Cassette\EffectKind;

/**
 * Scrubs an effect's `call` and `result` on their way into
 * {@see \Quiote\Replay\Replay\EffectLedger}, so redaction covers the effect ledger and not only
 * the request envelope.
 *
 * Placing this at the ledger rather than at each recorder is the whole point. Before it, the only
 * two consumers of {@see Redactor} in the tree were `RecorderMiddleware` and
 * `quioteframework/replay-propulsion`'s query recorder -- every other recorder wrote raw values
 * straight through: the full value of each environment variable read, outbound `Authorization`
 * headers and whole HTTP response bodies, cache values on both read and write, complete job
 * parameters, and every fetched database row. The ledger is the one point every recorder in every
 * driver package already funnels through, so scrubbing here is the only placement a newly written
 * recorder cannot forget.
 *
 * Redaction is per {@see EffectKind}, because the shapes differ and a single key-based pass over
 * all of them would miss most of what matters -- an outbound `Authorization` header is denied by
 * `replay.redact.headers` and not by `replay.redact.params`, and an environment variable's
 * sensitivity is carried by its own name rather than by an array key above it.
 *
 * What this cannot do, stated rather than implied: a value carries no field name of its own, so an
 * opaque cache value or an HTTP response body can only be matched by the key or header around it.
 * A serialized session blob cached under an innocuous key, or a token in a JSON response body,
 * passes through. `replay.capture_body`-style coarse controls, not this class, are the answer
 * there.
 */
final readonly class EffectRedactor
{
    /**
     * Case-insensitive substrings that mark an environment variable's value as a secret. Matched
     * as substrings, not exact names: environment variables are named per deployment
     * (`APP_DB_PASSWORD`, `AZURE_STORAGE_ACCOUNT_KEY`, `STRIPE_SECRET_KEY`), so an exact-match
     * denylist would need every name in every app enumerated to work at all.
     *
     * @var list<string>
     */
    private const DEFAULT_ENV_NEEDLES = [
        'password', 'passwd', 'secret', 'token', 'key', 'credential', 'private',
        'auth', 'dsn', 'connection_string', 'connectionstring', 'salt', 'cert',
    ];

    /** @param list<string> $envNeedles lower-cased substrings marking an env var name as secret */
    public function __construct(
        private Redactor $redactor,
        private array $envNeedles,
    ) {
    }

    /** Builds one from the current `replay.redact.*` config, alongside {@see Redactor::fromConfig()}. */
    public static function fromConfig(): self
    {
        return new self(
            Redactor::fromConfig(),
            array_values(array_map(
                strtolower(...),
                Config::getStringList('replay.redact.env', self::DEFAULT_ENV_NEEDLES),
            )),
        );
    }

    /**
     * The `call` payload, scrubbed for its kind.
     *
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public function redactCall(EffectKind $kind, array $call): array
    {
        return match ($kind) {
            // Outbound request headers carry the credential this application presents to the
            // remote one, which is exactly what replay.redact.headers denies by default.
            EffectKind::Http => $this->withRedactedHeaders($call),
            // A cache write carries its value in `call`, not `result`, so the key-based rule has
            // to run here too -- `{op: set, key: auth.token, value: ...}` has no denied array key
            // of its own to match on.
            EffectKind::Cache => $this->redactCacheValue($this->redactor->redactParams($call), $call['key'] ?? null),
            // Bound parameters, job parameters and Propulsion's own bound_params map are all
            // name-keyed structures, so the params denylist reaches them at any depth. A
            // positionally-bound parameter has no name to match and passes through -- the same
            // rule Redactor::redactColumnValue() already documents for a raw PDO bind.
            EffectKind::Db, EffectKind::Queue, EffectKind::Session
                => $this->redactor->redactParams($call),
            // The variable's own name is what marks it, and `call` carries only that name, not
            // its value -- nothing to scrub, and the name is what makes the effect readable.
            EffectKind::Env => $call,
            EffectKind::Mail, EffectKind::Clock, EffectKind::Entropy => $call,
        };
    }

    /**
     * The `result` payload, scrubbed for its kind.
     *
     * `$call` is passed in because several kinds carry the only identifying name for their result
     * there rather than in the result itself: an environment variable's name, a cache key.
     *
     * @param array<string, mixed> $call
     */
    public function redactResult(EffectKind $kind, array $call, mixed $result): mixed
    {
        return match ($kind) {
            EffectKind::Env => $this->redactEnvValue($call, $result),
            EffectKind::Cache => $this->redactCacheResult($call, $result),
            EffectKind::Http => $this->redactHttpResult($result),
            EffectKind::Db => is_array($result) ? $this->redactor->redactParams($result) : $result,
            EffectKind::Queue, EffectKind::Session, EffectKind::Mail, EffectKind::Clock, EffectKind::Entropy
                => is_array($result) ? $this->redactor->redactParams($result) : $result,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withRedactedHeaders(array $payload): array
    {
        $headers = $payload['headers'] ?? null;
        if (is_array($headers)) {
            $payload['headers'] = $this->redactor->redactHeaders(self::asHeaderLists($headers));
        }

        return $payload;
    }

    /**
     * A recorded response's status and headers are worth keeping; its body cannot be matched
     * against a field name at all, so it is left as captured. The headers are where a
     * `Set-Cookie` or a proxy credential would be.
     */
    private function redactHttpResult(mixed $result): mixed
    {
        return is_array($result) && array_is_list($result) === false
            ? $this->withRedactedHeaders(self::asStringKeyed($result))
            : $result;
    }

    /**
     * An environment variable is redacted on its own name: `getenv('DB_PASSWORD')` records the
     * password itself as the result, and no array key above it says so.
     *
     * @param array<string, mixed> $call
     */
    private function redactEnvValue(array $call, mixed $result): mixed
    {
        $name = $call['name'] ?? null;
        if (!is_string($name) || !$this->isSecretEnvName($name)) {
            return $result;
        }

        // false is getenv()'s "unset" -- not a secret, and the distinction a replay needs to
        // reproduce the branch the application took on it.
        return $result === false ? $result : '[REDACTED]';
    }

    private function isSecretEnvName(string $name): bool
    {
        $lower = strtolower($name);
        foreach ($this->envNeedles as $needle) {
            if ($needle !== '' && str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $call */
    private function redactCacheResult(array $call, mixed $result): mixed
    {
        return is_array($result)
            ? $this->redactCacheValue(self::asStringKeyed($result), $call['key'] ?? null)
            : $result;
    }

    /**
     * Scrubs a `value` entry against the cache key around it.
     *
     * A cache value is opaque: it has no field name of its own, so the key is the only thing to
     * match it against. A structured value is additionally descended into, which catches a cached
     * array carrying a `token`/`password` field regardless of what it was stored under. What this
     * cannot reach is a scalar secret cached under an innocuous key -- a serialized session blob
     * under `sess.abc123` -- and no name-based scheme can.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function redactCacheValue(array $payload, mixed $key): array
    {
        if (!array_key_exists('value', $payload)) {
            return $payload;
        }

        $value = $payload['value'];
        $payload['value'] = is_array($value)
            ? $this->redactor->redactParams($value)
            : $this->redactor->redactColumnValue(is_string($key) ? self::lastKeySegment($key) : null, $value);

        return $payload;
    }

    /**
     * The final segment of a namespaced cache key, so `auth.token` and `session:user:token` are
     * both matched against the same denylist a request parameter named `token` is.
     */
    private static function lastKeySegment(string $key): string
    {
        $segments = preg_split('/[.:\/|]/', $key) ?: [$key];

        return (string)($segments[count($segments) - 1] ?? $key);
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return array<string, array<string>>
     */
    private static function asHeaderLists(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $values) {
            if (!is_string($name)) {
                continue;
            }
            if (is_array($values)) {
                $result[$name] = array_values(array_map(
                    static fn(mixed $value): string => is_scalar($value) ? (string)$value : '',
                    $values,
                ));
                continue;
            }
            $result[$name] = [is_scalar($values) ? (string)$values : ''];
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private static function asStringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string)$key] = $item;
        }

        return $result;
    }
}
