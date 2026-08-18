<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Effect;
use Quiote\Replay\Cassette\EffectKind;
use Quiote\Replay\Http\HttpFingerprint;
use Quiote\Replay\Replay\EffectLedger;
use Quiote\Replay\Replay\StubbedHttpTransport;

final class StubbedHttpTransportTest extends TestCase
{
    private function transport(EffectLedger $ledger): StubbedHttpTransport
    {
        $psr17 = new Psr17Factory();

        return new StubbedHttpTransport($ledger, $psr17, $psr17);
    }

    public function testAMatchingRequestReturnsTheRecordedResponse(): void
    {
        $request = new Request('GET', 'https://example.test/a');
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Http, HttpFingerprint::of($request), [], [
                'status' => 201,
                'headers' => ['X-Test' => ['yes']],
                'body' => 'recorded-body',
            ]),
        ]);

        $response = $this->transport($ledger)->sendRequest($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Test'));
        $this->assertSame('recorded-body', (string) $response->getBody());
    }

    public function testARequestWithNoMatchingRecordedEffectRaisesRatherThanFabricatingA200(): void
    {
        $transport = $this->transport(new EffectLedger());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('#GET https://example\.test/missing#');
        $transport->sendRequest(new Request('GET', 'https://example.test/missing'));
    }

    public function testTwoIdenticalRequestsMatchTwoSeparatelyRecordedEffectsInOrder(): void
    {
        $request = new Request('GET', 'https://example.test/a');
        $fingerprint = HttpFingerprint::of($request);
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Http, $fingerprint, [], ['status' => 200, 'headers' => [], 'body' => 'first']),
            new Effect(1, EffectKind::Http, $fingerprint, [], ['status' => 200, 'headers' => [], 'body' => 'second']),
        ]);
        $transport = $this->transport($ledger);

        $first = $transport->sendRequest($request);
        $second = $transport->sendRequest($request);

        $this->assertSame('first', (string) $first->getBody());
        $this->assertSame('second', (string) $second->getBody());
    }

    public function testAnExhaustedLedgerRaisesOnTheNextIdenticalRequest(): void
    {
        $request = new Request('GET', 'https://example.test/a');
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Http, HttpFingerprint::of($request), [], ['status' => 200, 'headers' => [], 'body' => 'only']),
        ]);
        $transport = $this->transport($ledger);
        $transport->sendRequest($request);

        $this->expectException(RuntimeException::class);
        $transport->sendRequest($request);
    }

    /**
     * The stub must never make a real network call. Using a hostname that
     * cannot resolve proves this isn't just "trust me": if StubbedHttpTransport
     * ever fell through to a real transport, this would fail on DNS resolution
     * or hang on connect instead of answering instantly from the ledger.
     */
    public function testNeverMakesARealNetworkCallEvenForAnUnroutableHost(): void
    {
        $request = new Request('GET', 'http://this-host-does-not-exist.invalid/path');
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Http, HttpFingerprint::of($request), [], ['status' => 200, 'headers' => [], 'body' => 'stubbed']),
        ]);

        $response = $this->transport($ledger)->sendRequest($request);

        $this->assertSame('stubbed', (string) $response->getBody());
    }

    public function testARecordedEffectThatIsNotAValidHttpResponseShapeRaises(): void
    {
        $request = new Request('GET', 'https://example.test/a');
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Http, HttpFingerprint::of($request), [], 'not-a-response-array'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->transport($ledger)->sendRequest($request);
    }

    public function testMultipleHeaderValuesAreAllApplied(): void
    {
        $request = new Request('GET', 'https://example.test/a');
        $ledger = new EffectLedger([
            new Effect(0, EffectKind::Http, HttpFingerprint::of($request), [], [
                'status' => 200,
                'headers' => ['Set-Cookie' => ['a=1', 'b=2']],
                'body' => '',
            ]),
        ]);

        $response = $this->transport($ledger)->sendRequest($request);

        $this->assertSame(['a=1', 'b=2'], $response->getHeader('Set-Cookie'));
    }
}
