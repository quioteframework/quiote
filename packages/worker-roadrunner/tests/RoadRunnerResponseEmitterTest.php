<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Quiote\Http\Sse\SseEvent;
use Quiote\Http\Sse\SseStream;
use Quiote\Runtime\RoadRunner\RoadRunnerResponseEmitter;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;

/**
 * Captures the payload frames a real PSR7Worker produces, so these tests
 * exercise RoadRunner's own serialisation rather than a stand-in for it. Only
 * the relay at the very bottom is faked.
 */
final class RelayCapturingWorker implements WorkerInterface
{
    /** @var list<Payload> */
    public array $payloads = [];

    /** @var list<string> */
    public array $errors = [];

    public function waitPayload(): ?Payload
    {
        return null;
    }

    public function respond(Payload $payload): void
    {
        $this->payloads[] = $payload;
    }

    public function error(string $error): void
    {
        $this->errors[] = $error;
    }

    public function stop(): void
    {
    }

    public function hasPayload(?string $class = null): bool
    {
        return false;
    }

    public function getPayload(?string $class = null): ?Payload
    {
        return null;
    }
}

final class RoadRunnerResponseEmitterTest extends TestCase
{
    private function makeWorker(RelayCapturingWorker $relay): PSR7Worker
    {
        $psr17 = new Psr17Factory();

        return new PSR7Worker($relay, $psr17, $psr17, $psr17);
    }

    public function testItReportsStreamingSupport(): void
    {
        $relay = new RelayCapturingWorker();

        $this->assertTrue((new RoadRunnerResponseEmitter($this->makeWorker($relay)))->supportsStreaming());
    }

    public function testAnOrdinaryResponseGoesOutAsASingleFrame(): void
    {
        $relay = new RelayCapturingWorker();
        $psr17 = new Psr17Factory();
        $response = $psr17->createResponse(201)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($psr17->createStream('{"ok":true}'));

        (new RoadRunnerResponseEmitter($this->makeWorker($relay)))->emit($response);

        $this->assertCount(1, $relay->payloads);
        $this->assertSame('{"ok":true}', $relay->payloads[0]->body);
        $this->assertTrue($relay->payloads[0]->eos);
        $this->assertStringContainsString('application\\/json', $relay->payloads[0]->header);
        $this->assertStringContainsString('201', $relay->payloads[0]->header);
    }

    public function testAnEmptyBodyStillProducesAFrameWithItsStatus(): void
    {
        $relay = new RelayCapturingWorker();

        (new RoadRunnerResponseEmitter($this->makeWorker($relay)))
            ->emit((new Psr17Factory())->createResponse(204));

        $this->assertCount(1, $relay->payloads);
        $this->assertSame('', $relay->payloads[0]->body);
        $this->assertStringContainsString('204', $relay->payloads[0]->header);
    }

    public function testMultipleSetCookieHeadersAllSurvive(): void
    {
        $relay = new RelayCapturingWorker();
        $response = (new Psr17Factory())->createResponse(200)
            ->withAddedHeader('Set-Cookie', 'a=1; Path=/')
            ->withAddedHeader('Set-Cookie', 'b=2; Path=/');

        (new RoadRunnerResponseEmitter($this->makeWorker($relay)))->emit($response);

        $header = $relay->payloads[0]->header;
        $this->assertStringContainsString('a=1', $header);
        $this->assertStringContainsString('b=2', $header);
    }

    public function testAnSseBodyIsSentAsAFramePerEventRatherThanOneBlob(): void
    {
        $relay = new RelayCapturingWorker();
        $response = (new Psr17Factory())->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withBody(new SseStream([
                SseEvent::of('one', event: 'a'),
                SseEvent::of('two', event: 'b'),
            ]));

        (new RoadRunnerResponseEmitter($this->makeWorker($relay)))->emit($response);

        // Two events plus RoadRunner's closing frame: the client sees each event
        // as the action produces it, which is the entire point of SSE.
        $bodies = array_map(static fn(Payload $p): string => $p->body, $relay->payloads);
        $this->assertGreaterThanOrEqual(2, count($relay->payloads));
        $this->assertSame("event: a\ndata: one\n\n", $bodies[0]);
        $this->assertSame("event: b\ndata: two\n\n", $bodies[1]);
        $this->assertFalse($relay->payloads[0]->eos, 'an intermediate frame must not close the stream');
        $this->assertTrue($relay->payloads[count($relay->payloads) - 1]->eos);
    }

    public function testEventsLargerThanTheChunkSizeAreSplitAcrossFrames(): void
    {
        $relay = new RelayCapturingWorker();
        $response = (new Psr17Factory())->createResponse(200)
            ->withBody(new SseStream([str_repeat('x', 50)]));

        (new RoadRunnerResponseEmitter($this->makeWorker($relay), chunkSize: 8))->emit($response);

        $reassembled = implode('', array_map(static fn(Payload $p): string => $p->body, $relay->payloads));
        $this->assertSame("data: " . str_repeat('x', 50) . "\n\n", $reassembled);
        $this->assertGreaterThan(2, count($relay->payloads));
    }

    public function testAnEmptyEventStreamStillClosesCleanly(): void
    {
        $relay = new RelayCapturingWorker();
        $response = (new Psr17Factory())->createResponse(200)->withBody(new SseStream([]));

        (new RoadRunnerResponseEmitter($this->makeWorker($relay)))->emit($response);

        $this->assertCount(1, $relay->payloads);
        $this->assertSame('', $relay->payloads[0]->body);
        $this->assertTrue($relay->payloads[0]->eos);
    }

    public function testAnAbsurdChunkSizeIsClampedRatherThanLoopingForever(): void
    {
        $relay = new RelayCapturingWorker();
        $response = (new Psr17Factory())->createResponse(200)->withBody(new SseStream(['x']));

        (new RoadRunnerResponseEmitter($this->makeWorker($relay), chunkSize: 0))->emit($response);

        $reassembled = implode('', array_map(static fn(Payload $p): string => $p->body, $relay->payloads));
        $this->assertSame("data: x\n\n", $reassembled);
    }
}
