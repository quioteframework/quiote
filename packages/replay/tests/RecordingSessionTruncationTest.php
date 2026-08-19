<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Cassette\Cassette;
use Quiote\Replay\Cassette\CassetteCodec;
use Quiote\Replay\Recording\RecordingSession;

/**
 * The byte budget exists to stop an oversized request producing an oversized cassette. Cutting
 * on a byte boundary defeated that: a `utf8` body cut mid-character left the section invalid
 * UTF-8, and the codec's `JSON_THROW_ON_ERROR` then refused the whole payload -- so the guard
 * against a large cassette became total loss of the cassette. These pin the cut to a character
 * boundary and assert the bounded session still encodes.
 */
final class RecordingSessionTruncationTest extends TestCase
{
    private static function cassetteFrom(RecordingSession $session): Cassette
    {
        return new Cassette(
            schemaVersion: CassetteCodec::CURRENT_SCHEMA_VERSION,
            meta: ['id' => 'trunc', 'recorded_at' => '2026-08-19T00:00:00+00:00'],
            request: $session->request() ?? [],
            resolved: [],
            session: null,
            user: null,
            effects: $session->boundedEffects(),
            response: $session->response() ?? [],
            exception: null,
            log: null,
        );
    }

    /** @return array{encoding: string, content: string, truncated: bool} */
    private static function body(string $content, string $encoding = 'utf8'): array
    {
        return ['encoding' => $encoding, 'content' => $content, 'truncated' => false];
    }

    /**
     * The retained `body.content` of a bounded section.
     *
     * @param array<string, mixed>|null $section
     */
    private static function contentOf(?array $section): string
    {
        self::assertIsArray($section, 'Expected the session to hold this section.');
        $body = $section['body'] ?? null;
        self::assertIsArray($body);
        $content = $body['content'] ?? null;
        self::assertIsString($content);

        return $content;
    }

    /**
     * The `body.truncated` flag of a bounded section.
     *
     * @param array<string, mixed>|null $section
     */
    private static function truncatedFlagOf(?array $section): bool
    {
        self::assertIsArray($section, 'Expected the session to hold this section.');
        $body = $section['body'] ?? null;
        self::assertIsArray($body);

        return (bool)($body['truncated'] ?? false);
    }

    public function testAMultiByteBodyIsCutOnACharacterBoundaryAndStillEncodes(): void
    {
        // The three-byte euro sign straddles the 10-byte budget: a byte-wise cut keeps its first
        // byte only and the payload stops being valid UTF-8.
        $session = new RecordingSession(maxBytes: 10, maxEffects: 100);
        $session->setRequest(['method' => 'POST', 'uri' => '/x', 'body' => self::body('aaaaaaaaa€tail')]);
        $session->setResponse(['status' => 200, 'headers' => [], 'body' => self::body('')]);

        $content = self::contentOf($session->request());
        $this->assertSame('aaaaaaaaa', $content, 'The partial character must be dropped, not half-kept.');
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'));
        $this->assertTrue(self::truncatedFlagOf($session->request()));
        $this->assertTrue($session->requestBodyTruncated());

        // The point of the fix: the cassette encodes rather than being lost.
        $encoded = (new CassetteCodec())->encode(self::cassetteFrom($session));
        $this->assertSame($content, self::contentOf((new CassetteCodec())->decode($encoded)->request));
    }

    public function testACharacterThatCannotFitAtAllYieldsAnEmptyBodyRatherThanAPartialOne(): void
    {
        $session = new RecordingSession(maxBytes: 2, maxEffects: 100);
        $session->setRequest(['method' => 'POST', 'uri' => '/x', 'body' => self::body('€€€')]);
        $session->setResponse(['status' => 200, 'headers' => [], 'body' => self::body('')]);

        $this->assertSame('', self::contentOf($session->request()));
        $this->assertTrue(self::truncatedFlagOf($session->request()));

        $this->assertNotSame('', (new CassetteCodec())->encode(self::cassetteFrom($session)));
    }

    public function testTheBudgetChargesTheRetainedLengthNotTheRequestedOne(): void
    {
        // Request body: budget 10, so 9 bytes are retained (the euro sign does not fit). The
        // response then still has 1 byte of budget, not 0 -- charging $remaining rather than the
        // actual cut would have spent the whole budget on the request.
        $session = new RecordingSession(maxBytes: 10, maxEffects: 100);
        $session->setRequest(['method' => 'POST', 'uri' => '/x', 'body' => self::body('aaaaaaaaa€tail')]);
        $session->setResponse(['status' => 200, 'headers' => [], 'body' => self::body('bbbb')]);

        $this->assertSame('b', self::contentOf($session->response()));
        $this->assertTrue(self::truncatedFlagOf($session->response()));
        $this->assertTrue($session->responseBodyTruncated());
    }

    public function testAnAsciiBodyTruncatesToExactlyTheBudget(): void
    {
        $session = new RecordingSession(maxBytes: 4, maxEffects: 100);
        $session->setRequest(['method' => 'POST', 'uri' => '/x', 'body' => self::body('0123456789')]);

        $this->assertSame('0123', self::contentOf($session->request()));
    }

    public function testABase64BodyIsUnaffectedByCharacterBoundaries(): void
    {
        // base64 is pure ASCII, so byte and character cuts coincide; the section stays encodable.
        $session = new RecordingSession(maxBytes: 6, maxEffects: 100);
        $session->setRequest(['method' => 'POST', 'uri' => '/x', 'body' => self::body(base64_encode(random_bytes(64)), 'base64')]);
        $session->setResponse(['status' => 200, 'headers' => [], 'body' => self::body('')]);

        $this->assertSame(6, strlen(self::contentOf($session->request())));
        $this->assertNotSame('', (new CassetteCodec())->encode(self::cassetteFrom($session)));
    }

    public function testABodyThatFitsIsNotMarkedTruncated(): void
    {
        $session = new RecordingSession(maxBytes: 1024, maxEffects: 100);
        $session->setRequest(['method' => 'POST', 'uri' => '/x', 'body' => self::body('pässwörd ok €')]);

        $this->assertSame('pässwörd ok €', self::contentOf($session->request()));
        $this->assertFalse(self::truncatedFlagOf($session->request()));
        $this->assertFalse($session->requestBodyTruncated());
    }

    public function testASectionWithoutAStringBodyIsPassedThroughUnchanged(): void
    {
        $session = new RecordingSession(maxBytes: 4, maxEffects: 100);
        $session->setResponse(['status' => 204, 'headers' => []]);

        $this->assertSame(['status' => 204, 'headers' => []], $session->response());
        $this->assertFalse($session->responseBodyTruncated());
    }
}
