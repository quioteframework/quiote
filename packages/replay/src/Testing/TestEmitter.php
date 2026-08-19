<?php

declare(strict_types=1);

namespace Quiote\Replay\Testing;

use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteId;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Support\Compiler\EmittedArtifact;

/**
 * Turns one {@see Cassette} into a `Quiote\Support\Compiler\EmittedArtifact` --
 * PHP source for a {@see ReplayTestCase} subclass. This class only generates
 * source text; writing it (and the cassette copy it references) to disk is
 * the caller's job, via `Quiote\Support\Compiler\FilesystemArtifactWriter`,
 * the same generator/writer split `Quiote\Validator\Compiler\ValidatorCompiler`
 * already follows.
 *
 * Scaffolds a deliberately narrow assertion set, no more: `assertStatus()`
 * always, `assertJsonEquals()` for a JSON response body, `assertSee()` on the
 * exception message for an error cassette, `assertHeader('Location', ...)`
 * for a redirect. A DB write or enqueued-job effect is called out as a plain
 * comment naming the SQL/fingerprint -- not as commented-out *code* calling
 * an assertion method that does not exist yet (no such helper exists for an
 * effect today), which would mislead a developer into uncommenting a call
 * that fails.
 */
final class TestEmitter
{
    public function emit(Cassette $cassette, CassetteId $id, bool $expectFixed = false): EmittedArtifact
    {
        $className = self::className($id);
        $methodName = self::methodName($cassette, $expectFixed);
        $body = $expectFixed
            ? $this->expectFixedBody($cassette, $id)
            : $this->pinBehaviourBody($cassette, $id);

        $recordedAt = $cassette->meta['recorded_at'] ?? null;
        $header = sprintf(
            '/** Generated from cassette "%s"%s. Edit freely -- regenerating overwrites this file. */',
            $id->raw,
            is_string($recordedAt) && $recordedAt !== '' ? ", recorded $recordedAt" : '',
        );

        $source = implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'use Quiote\Replay\Testing\ReplayTestCase;',
            '',
            $header,
            "final class $className extends ReplayTestCase",
            '{',
            "    public function $methodName(): void",
            '    {',
            self::indent($body, 8),
            '    }',
            '}',
            '',
        ]);

        return EmittedArtifact::fromSource($source, "tests/Replay/{$className}.php");
    }

    private function pinBehaviourBody(Cassette $cassette, CassetteId $id): string
    {
        $calls = [];

        $status = $cassette->response['status'] ?? null;
        if (is_int($status)) {
            $calls[] = "->assertStatus($status)";
        }

        $jsonBody = self::decodedJsonBody($cassette->response);
        if ($jsonBody !== null) {
            $calls[] = '->assertJsonEquals(' . self::exportArray($jsonBody) . ')';
        }

        $exceptionMessage = $cassette->exception['message'] ?? null;
        if (is_string($exceptionMessage) && $exceptionMessage !== '') {
            $calls[] = '->assertSee(' . var_export($exceptionMessage, true) . ')';
        }

        $location = self::redirectLocation($cassette->response);
        if ($location !== null) {
            $calls[] = "->assertHeader('Location', " . var_export($location, true) . ')';
        }

        $head = "\$this->replay(__DIR__ . '/cassettes/{$id->slug}.qcast')";
        $lines = [self::chain($head, $calls)];

        $comments = self::effectComments($cassette->effects);
        if ($comments !== []) {
            $lines[] = '';
            array_push($lines, ...$comments);
        }

        return implode("\n", $lines);
    }

    private function expectFixedBody(Cassette $cassette, CassetteId $id): string
    {
        $status = $cassette->response['status'] ?? null;
        $exceptionMessage = $cassette->exception['message'] ?? null;

        $summary = is_int($status) ? "status $status" : 'an unknown status';
        $exceptionClass = $cassette->exception['class'] ?? null;
        if (is_string($exceptionMessage) && $exceptionMessage !== '') {
            $summary .= sprintf(' (%s: %s)', is_string($exceptionClass) ? $exceptionClass : 'exception', $exceptionMessage);
        }

        $incomplete = "Fix the recorded bug ($summary), then replace the line below with assertions describing the fixed behaviour.";

        return implode("\n", [
            "\$response = \$this->replay(__DIR__ . '/cassettes/{$id->slug}.qcast');",
            '',
            "// Recorded (buggy) response: $summary.",
            '$this->markTestIncomplete(' . var_export($incomplete, true) . ');',
        ]);
    }

    /** @param list<string> $calls */
    private static function chain(string $head, array $calls): string
    {
        if ($calls === []) {
            return "$head;";
        }

        $lines = [$head];
        foreach ($calls as $i => $call) {
            $indented = self::indent($call, 4);
            $lines[] = $i === array_key_last($calls) ? $indented . ';' : $indented;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<Effect> $effects
     * @return list<string>
     */
    private static function effectComments(array $effects): array
    {
        $notes = [];
        foreach ($effects as $effect) {
            if ($effect->kind === EffectKind::Db && self::looksLikeWrite($effect)) {
                $sql = $effect->call['sql'] ?? null;
                if (is_string($sql)) {
                    $notes[] = '// Recorded DB write -- assert this if it matters: ' . self::truncate($sql, 160);
                }
            } elseif ($effect->kind === EffectKind::Queue) {
                $notes[] = '// Recorded enqueued job -- assert this if it matters: ' . self::truncate($effect->fingerprint, 160);
            }
        }

        return $notes === [] ? [] : array_merge(['// Effects this request recorded, not yet directly assertable:'], $notes);
    }

    private static function looksLikeWrite(Effect $effect): bool
    {
        $sql = $effect->call['sql'] ?? null;

        return is_string($sql) && preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql) === 1;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<array-key, mixed>|null
     */
    private static function decodedJsonBody(array $response): ?array
    {
        $contentType = self::headerValue(is_array($response['headers'] ?? null) ? $response['headers'] : [], 'Content-Type');
        if ($contentType === null || !str_contains(strtolower($contentType), 'json')) {
            return null;
        }

        $decoded = self::decodeBody($response['body'] ?? null);
        if ($decoded === null) {
            return null;
        }

        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    /** @param array<string, mixed> $response */
    private static function redirectLocation(array $response): ?string
    {
        $status = $response['status'] ?? null;
        if (!is_int($status) || $status < 300 || $status >= 400) {
            return null;
        }

        return self::headerValue(is_array($response['headers'] ?? null) ? $response['headers'] : [], 'Location');
    }

    private static function decodeBody(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }
        $content = $body['content'] ?? null;
        if (!is_string($content) || $content === '') {
            return null;
        }
        if (($body['encoding'] ?? 'utf8') === 'base64') {
            $decoded = base64_decode($content, true);

            return $decoded !== false ? $decoded : null;
        }

        return $content;
    }

    /** @param array<array-key, mixed> $headers */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (!is_string($key) || strcasecmp($key, $name) !== 0) {
                continue;
            }
            if (is_array($values) && $values !== [] && is_scalar($values[array_key_first($values)])) {
                return (string) $values[array_key_first($values)];
            }
            if (is_scalar($values)) {
                return (string) $values;
            }
        }

        return null;
    }

    private static function truncate(string $value, int $max): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

        return strlen($collapsed) > $max ? substr($collapsed, 0, $max - 1) . '…' : $collapsed;
    }

    /** @param array<array-key, mixed> $value */
    private static function exportArray(array $value): string
    {
        $exported = str_replace('array (', '[', var_export($value, true));
        $exported = preg_replace('/^(\s*)\)/m', '$1]', $exported) ?? $exported;
        // var_export puts a nested array's opening bracket on its own line; merge it back onto
        // the preceding "'key' =>" line, matching this codebase's own array literal style.
        $exported = preg_replace('/=> \n\s*\[/', '=> [', $exported) ?? $exported;

        return $exported;
    }

    private static function indent(string $block, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);

        return implode("\n", array_map(
            static fn(string $line): string => $line === '' ? '' : $pad . $line,
            explode("\n", $block),
        ));
    }

    private static function className(CassetteId $id): string
    {
        return 'Replay' . str_replace('-', '_', $id->slug) . 'Test';
    }

    private static function methodName(Cassette $cassette, bool $expectFixed): string
    {
        $module = $cassette->resolved['module'] ?? null;
        $action = $cassette->resolved['action'] ?? null;
        $subject = (is_string($module) ? self::sanitizeIdentifierPart($module) : '')
            . (is_string($action) ? self::sanitizeIdentifierPart($action) : '');

        return $expectFixed ? "test{$subject}FixesRecordedBug" : "test{$subject}ReproducesRecordedResponse";
    }

    private static function sanitizeIdentifierPart(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';

        return $clean === '' ? '' : ucfirst($clean);
    }
}
