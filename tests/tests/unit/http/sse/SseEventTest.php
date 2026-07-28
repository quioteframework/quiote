<?php

use PHPUnit\Framework\TestCase;
use Quiote\Http\Sse\SseEvent;

class SseEventTest extends TestCase
{
    public function testFormatWithDataOnly(): void
    {
        $event = SseEvent::of('hello');
        $this->assertSame("data: hello\n\n", $event->format());
    }

    public function testFormatWithEventIdAndRetry(): void
    {
        $event = SseEvent::of('hello', event: 'greeting', id: '42', retryMs: 3000);
        $this->assertSame("event: greeting\nid: 42\nretry: 3000\ndata: hello\n\n", $event->format());
    }

    public function testFormatSplitsMultiLineDataIntoMultipleDataLines(): void
    {
        $event = SseEvent::of("line one\nline two");
        $this->assertSame("data: line one\ndata: line two\n\n", $event->format());
    }

    public function testOfJsonEncodesArrayData(): void
    {
        $event = SseEvent::of(['foo' => 'bar']);
        $this->assertSame('{"foo":"bar"}', $event->data);
    }
}
