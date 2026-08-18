<?php

declare(strict_types=1);

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Quiote\Http\Client\Exception\NetworkException;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Http\RecordingHttpTransport;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Support\Clock\FrozenClock;
use Quiote\Test\Http\Client\RecordingTransport;

/** A minimal, deliberately non-seekable PSR-7 stream test double. */
final class RecordingHttpTransportTestUnseekableStream implements StreamInterface
{
    public function __construct(private string $content)
    {
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return strlen($this->content);
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('this stream is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('this stream is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('this stream is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return $this->content;
    }

    public function getContents(): string
    {
        return $this->content;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}

final class RecordingHttpTransportTest extends TestCase
{
    public function testASuccessfulRequestRecordsExactlyOneHttpEffect(): void
    {
        $inner = new RecordingTransport(new Response(200, [], 'ok'));
        $ledger = new EffectLedger();
        $transport = new RecordingHttpTransport($inner, $ledger);

        $transport->sendRequest(new Request('GET', 'https://example.test/a'));

        $this->assertCount(1, $ledger->all());
        $this->assertSame(EffectKind::Http, $ledger->all()[0]->kind);
    }

    public function testSameMethodUriAndBodyProduceTheSameFingerprint(): void
    {
        $inner = new RecordingTransport(new Response(200), new Response(200));
        $ledger = new EffectLedger();
        $transport = new RecordingHttpTransport($inner, $ledger);

        $transport->sendRequest(new Request('POST', 'https://example.test/a', [], 'same-body'));
        $transport->sendRequest(new Request('POST', 'https://example.test/a', [], 'same-body'));

        $fingerprints = array_map(static fn($e) => $e->fingerprint, $ledger->all());
        $this->assertSame($fingerprints[0], $fingerprints[1]);
    }

    public function testADifferentBodyProducesADifferentFingerprint(): void
    {
        $inner = new RecordingTransport(new Response(200), new Response(200));
        $ledger = new EffectLedger();
        $transport = new RecordingHttpTransport($inner, $ledger);

        $transport->sendRequest(new Request('POST', 'https://example.test/a', [], 'body-one'));
        $transport->sendRequest(new Request('POST', 'https://example.test/a', [], 'body-two'));

        $fingerprints = array_map(static fn($e) => $e->fingerprint, $ledger->all());
        $this->assertNotSame($fingerprints[0], $fingerprints[1]);
    }

    public function testTheRealResponseIsReturnedUnmodifiedAndItsBodyIsStillReadable(): void
    {
        $inner = new RecordingTransport(new Response(201, ['X-Test' => 'yes'], 'response-body'));
        $ledger = new EffectLedger();
        $transport = new RecordingHttpTransport($inner, $ledger);

        $response = $transport->sendRequest(new Request('GET', 'https://example.test/a'));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Test'));
        $this->assertSame('response-body', (string) $response->getBody());
    }

    public function testTheRecordedEffectCapturesStatusHeadersAndBody(): void
    {
        $inner = new RecordingTransport(new Response(404, ['X-Reason' => 'missing'], 'not found'));
        $ledger = new EffectLedger();
        $transport = new RecordingHttpTransport($inner, $ledger);

        $transport->sendRequest(new Request('GET', 'https://example.test/missing'));

        $result = $ledger->all()[0]->result;
        $this->assertIsArray($result);
        $this->assertSame(404, $result['status']);
        $this->assertSame('not found', $result['body']);

        $headers = $result['headers'];
        $this->assertIsArray($headers);
        $this->assertSame(['missing'], $headers['X-Reason']);
    }

    public function testARequestWhoseInnerTransportThrowsDoesNotRecordAndPropagates(): void
    {
        $inner = new RecordingTransport(null); // null outcome => throws NetworkException
        $ledger = new EffectLedger();
        $transport = new RecordingHttpTransport($inner, $ledger);

        try {
            $transport->sendRequest(new Request('GET', 'https://example.test/a'));
            $this->fail('expected NetworkException to propagate');
        } catch (NetworkException) {
            // expected
        }

        $this->assertSame([], $ledger->all());
    }

    public function testTwoSequentialDifferentRequestsProduceTwoLedgerEntriesInOrder(): void
    {
        $inner = new RecordingTransport(new Response(200), new Response(200));
        $ledger = new EffectLedger();
        $transport = new RecordingHttpTransport($inner, $ledger);

        $transport->sendRequest(new Request('GET', 'https://example.test/a'));
        $transport->sendRequest(new Request('GET', 'https://example.test/b'));

        $all = $ledger->all();
        $this->assertCount(2, $all);
        $this->assertStringContainsString('/a', $all[0]->fingerprint);
        $this->assertStringContainsString('/b', $all[1]->fingerprint);
    }

    public function testANonSeekableBodyDoesNotCrashAndDegradesTheFingerprintInstead(): void
    {
        $nonSeekable = new RecordingHttpTransportTestUnseekableStream('unseekable-content');
        $request = (new Request('POST', 'https://example.test/a'))->withBody($nonSeekable);

        $inner = new RecordingTransport(new Response(200));
        $ledger = new EffectLedger();
        $transport = new RecordingHttpTransport($inner, $ledger);

        $transport->sendRequest($request);

        $this->assertCount(1, $ledger->all());
        $this->assertStringContainsString('unseekable-body', $ledger->all()[0]->fingerprint);
    }

    public function testDurationIsRecordedFromTheInjectedClockNotWallClockTime(): void
    {
        $clock = new FrozenClock(0.0, 5.0);
        $inner = new RecordingTransport(new Response(200));
        $ledger = new EffectLedger();
        $transport = new RecordingHttpTransport($inner, $ledger, $clock);

        $transport->sendRequest(new Request('GET', 'https://example.test/a'));

        $this->assertSame(0, $ledger->all()[0]->durationMicros);
    }
}
