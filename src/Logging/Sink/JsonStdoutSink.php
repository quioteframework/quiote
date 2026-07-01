<?php

namespace Agavi\Logging\Sink;

use Agavi\Logging\LogEvent;

/**
 * Default container sink: one compact JSON object per line to stdout.
 *
 * Designed for FrankenPHP/Caddy → AKS → Azure Log Analytics:
 *  - Compact encoding (never JSON_PRETTY_PRINT) so embedded newlines in a value
 *    (e.g. a stack trace) are escaped and each event stays on ONE physical line
 *    = one Log Analytics record.
 *  - Written straight to php://stdout as bare JSON — NOT via error_log() — so
 *    Caddy's own JSON logger does not wrap it into a "msg" string ("double JSON").
 *  - A "src":"app" discriminator distinguishes app events from Caddy access logs.
 *
 * Field schema (flat, KQL-friendly). Reserved keys always win over user
 * properties on a name collision:
 *   ts, level, category, message, template?, src, exception?  + flattened
 *   scope/properties.
 */
final class JsonStdoutSink extends AbstractStreamSink
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PARTIAL_OUTPUT_ON_ERROR
        | JSON_INVALID_UTF8_SUBSTITUTE;

    protected function format(LogEvent $event): string
    {
        // Start with user data flattened, then overwrite with reserved keys so
        // the reserved fields are always well-formed.
        $record = [...$event->scope, ...$event->properties];

        $record['ts']       = self::formatTimestamp($event->timestamp);
        $record['level']    = $event->level->label();
        $record['category'] = $event->category;
        $record['message']  = $event->renderMessage();
        $record['src']      = 'app';

        // Only include the raw template when it actually carries placeholders
        // (otherwise it equals "message").
        if (str_contains($event->messageTemplate, '{')) {
            $record['template'] = $event->messageTemplate;
        }

        if ($event->exception !== null) {
            $record['exception'] = self::formatException($event->exception);
        }

        $json = json_encode($record, self::FLAGS);
        if ($json === false) {
            // Should not happen with PARTIAL_OUTPUT_ON_ERROR, but never emit a
            // broken line: fall back to a minimal, valid record.
            $json = json_encode([
                'ts'       => $record['ts'],
                'level'    => $record['level'],
                'category' => $record['category'],
                'message'  => $record['message'],
                'src'      => 'app',
                'log_error' => 'json_encode_failed: ' . json_last_error_msg(),
            ], self::FLAGS) ?: '{"src":"app","log_error":"json_encode_failed"}';
        }
        return $json;
    }

    /**
     * @return array<string,mixed>
     */
    private static function formatException(\Throwable $e): array
    {
        $out = [];
        $current = $e;
        $depth = 0;
        // Flatten the cause chain (bounded) without recursion into the trace.
        while ($current !== null && $depth < 5) {
            $out[] = [
                'class'   => $current::class,
                'message' => $current->getMessage(),
                'code'    => $current->getCode(),
                'file'    => $current->getFile(),
                'line'    => $current->getLine(),
            ];
            $current = $current->getPrevious();
            $depth++;
        }
        return [
            'chain' => $out,
            // Trace as a single string; newlines are escaped by json_encode so
            // the whole event remains one physical line.
            'trace' => $e->getTraceAsString(),
        ];
    }
}
