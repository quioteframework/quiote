<?php

namespace Quiote\Http\Sse;

/**
 * A single Server-Sent Events wire-format message.
 * @see https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation
 */
final class SseEvent
{
    public function __construct(
        public readonly string $data,
        public readonly ?string $event = null,
        public readonly ?string $id = null,
        public readonly ?int $retryMs = null,
    ) {
    }

    /**
     * @param string|array<mixed> $data Arrays are JSON-encoded.
     */
    public static function of(string|array $data, ?string $event = null, ?string $id = null, ?int $retryMs = null): self
    {
        return new self(is_array($data) ? (string)json_encode($data) : $data, $event, $id, $retryMs);
    }

    /**
     * Renders the event as its `text/event-stream` representation.
     *
     * The optional `event`, `id` and `retry` fields are emitted first and only when set, then
     * the data, split into one `data:` line per embedded newline as the wire format requires.
     * The result ends with the blank line that terminates the message.
     */
    public function format(): string
    {
        $lines = [];
        if ($this->event !== null) {
            $lines[] = 'event: ' . $this->event;
        }
        if ($this->id !== null) {
            $lines[] = 'id: ' . $this->id;
        }
        if ($this->retryMs !== null) {
            $lines[] = 'retry: ' . $this->retryMs;
        }
        foreach (explode("\n", $this->data) as $dataLine) {
            $lines[] = 'data: ' . $dataLine;
        }
        return implode("\n", $lines) . "\n\n";
    }
}
