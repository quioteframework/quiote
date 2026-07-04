<?php

namespace Quiote\Telemetry\Dashboard;

/**
 * Strips terminal-escape introducer bytes from telemetry-derived strings
 * (span names, status messages, route labels, attribute values) before they
 * reach a `TextWidget`. `symfony/tui`'s `TextWidget` renders its text
 * ANSI-passthrough and unsanitized by design (see that class's own
 * docblock) -- fine for developer-authored UI strings, but every string this
 * dashboard displays ultimately comes from an instrumented app's telemetry
 * export, which the dashboard does not control. A hostile or buggy app could
 * otherwise inject ESC/CSI/OSC sequences via, e.g., a span attribute value,
 * to corrupt or hijack the terminal display.
 *
 * Deliberately not reusing `Symfony\Component\Tui\Widget\Util\StringUtils`
 * (which does the same thing) -- that class is marked `@internal` to the
 * `symfony/tui` package.
 */
final class TextSanitizer
{
    /**
     * Removes C0 controls (except tab/newline), DEL, and the UTF-8 encoding
     * of C1 controls -- the same set `symfony/tui`'s internal sanitizer
     * strips, so behavior matches widgets that do sanitize their own input
     * (e.g. `InputWidget`).
     */
    public static function sanitize(string $value): string
    {
        return preg_replace("/[\x00-\x08\x0b-\x1f\x7f]|\xc2[\x80-\x9f]/", '', $value) ?? '';
    }
}
