<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Quiote\Http\Sse\SseEvent;
use Quiote\Http\Sse\SseStream;
use Quiote\Runtime\Emitter\ResponseEmitterInterface;
use Quiote\Runtime\Emitter\SapiEmitter;
use Quiote\Runtime\HttpEmitter;

/**
 * Each test runs in its own process for the same reason HttpEmitterTest's do:
 * emit() calls header()/http_response_code(), which only behave correctly before
 * any real output has reached the SAPI.
 */
final class SapiEmitterTest extends TestCase
{
    public function testItIsAResponseEmitterAndReportsStreamingSupport(): void
    {
        $emitter = new SapiEmitter();

        $this->assertInstanceOf(ResponseEmitterInterface::class, $emitter);
        $this->assertTrue($emitter->supportsStreaming());
    }

    public function testTheOldHttpEmitterNameStillResolvesToTheSameBehaviour(): void
    {
        // Apps and the existing HttpEmitterTest reference this name; it is kept as
        // a thin subclass rather than broken.
        $this->assertInstanceOf(SapiEmitter::class, new HttpEmitter());
    }

    #[RunInSeparateProcess]
    public function testAPlainBodyIsWrittenToTheSapi(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(201)->withBody($factory->createStream('hello'));

        ob_start();
        (new SapiEmitter())->emit($response);
        $output = ob_get_clean();

        $this->assertSame('hello', $output);
        $this->assertSame(201, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testAnEmptyBodyEmitsNothing(): void
    {
        ob_start();
        (new SapiEmitter())->emit((new Psr17Factory())->createResponse(204));
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertSame(204, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testSseEventsAreFlushedInOrder(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)
            ->withHeader('Content-Type', 'text/event-stream')
            ->withBody(new SseStream([SseEvent::of('one', event: 'a'), SseEvent::of('two', event: 'b')]));

        ob_start();
        (new SapiEmitter())->emit($response);
        $output = ob_get_clean();

        $this->assertSame("event: a\ndata: one\n\nevent: b\ndata: two\n\n", $output);
    }

    #[RunInSeparateProcess]
    public function testTheCallersOwnOutputBufferSurvivesEmission(): void
    {
        // emitStreaming() deliberately doesn't touch the userland buffer stack;
        // tearing it down would also destroy whatever the caller set up.
        $level = ob_get_level();

        ob_start();
        (new SapiEmitter())->emit(
            (new Psr17Factory())->createResponse(200)->withBody(new SseStream(['tick']))
        );
        $output = ob_get_clean();

        $this->assertSame("data: tick\n\n", $output);
        $this->assertSame($level, ob_get_level());
    }
}
