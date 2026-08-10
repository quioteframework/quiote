<?php

declare(strict_types=1);

use Quiote\Controller\Controller;
use Quiote\Execution\SlotDispatcher;
use Quiote\Testing\UnitTestCase;

/**
 * The slot cache's payload format and the exception reporter beside it.
 *
 * A slot's cached content carries its own monotonic expiry stamp, checked
 * independently of the backend's wall-clock expiry -- a cache whose clock
 * disagrees with this process would otherwise serve content the slot itself
 * considers stale. Entries written without a TTL keep the backend's own
 * expiry and are stored raw.
 */
final class SlotDispatcherCachePayloadTest extends UnitTestCase
{
    private const MARKER = "\x00SCTTL1\x00";

    private function dispatcher(): SlotDispatcher
    {
        return new SlotDispatcher($this->getContext()->getContainer()->get(Controller::class));
    }

    private function encode(string $content, ?int $ttl): string
    {
        $result = (new ReflectionMethod(SlotDispatcher::class, 'encodeSlotCachePayload'))
            ->invoke($this->dispatcher(), $content, $ttl);
        $this->assertIsString($result);

        return $result;
    }

    private function decode(mixed $payload): ?string
    {
        $result = (new ReflectionMethod(SlotDispatcher::class, 'decodeSlotCachePayload'))
            ->invoke($this->dispatcher(), $payload);
        $this->assertTrue($result === null || is_string($result));

        return $result;
    }

    // --- encoding ----------------------------------------------------------

    /** No TTL means the backend's own expiry governs, so nothing is wrapped. */
    public function testContentWithoutATtlIsStoredRaw(): void
    {
        $this->assertSame('<div>slot</div>', $this->encode('<div>slot</div>', null));
    }

    public function testANonPositiveTtlIsTreatedAsNoTtl(): void
    {
        $this->assertSame('<div>slot</div>', $this->encode('<div>slot</div>', 0));
        $this->assertSame('<div>slot</div>', $this->encode('<div>slot</div>', -30));
    }

    public function testAPositiveTtlWrapsTheContentWithAnExpiryStamp(): void
    {
        $payload = $this->encode('<div>slot</div>', 60);

        $this->assertStringStartsWith(self::MARKER, $payload);

        $decoded = json_decode(substr($payload, strlen(self::MARKER)), true);
        $this->assertIsArray($decoded);
        $this->assertSame('<div>slot</div>', $decoded['c']);
        $this->assertGreaterThan(hrtime(true), $decoded['e'], 'the stamp is in the future');
    }

    /**
     * Content that will not survive json_encode (invalid UTF-8 from an action
     * emitting binary) is stored raw rather than dropped: losing the cache
     * entry entirely would be a worse outcome than losing its TTL stamp.
     */
    public function testContentThatCannotBeJsonEncodedIsStoredRaw(): void
    {
        $binary = "\xB1\x31 invalid utf-8";

        $this->assertSame($binary, $this->encode($binary, 60));
    }

    // --- decoding ----------------------------------------------------------

    public function testAWrappedPayloadRoundTripsWhileFresh(): void
    {
        $this->assertSame('<div>slot</div>', $this->decode($this->encode('<div>slot</div>', 60)));
    }

    /** An entry written without a TTL is always a hit; its expiry is the backend's business. */
    public function testAnUnwrappedPayloadIsAlwaysAHit(): void
    {
        $this->assertSame('<div>slot</div>', $this->decode('<div>slot</div>'));
        $this->assertSame('', $this->decode(''));
    }

    /** A cache miss hands back something that is not a string at all. */
    public function testANonStringCachedValueIsAMiss(): void
    {
        foreach ([null, false, 42, ['array'], new stdClass()] as $value) {
            $this->assertNull($this->decode($value));
        }
    }

    /**
     * The whole point of the stamp: expired content is a miss here even when
     * the backend still considers it fresh, so the slot re-executes.
     */
    public function testContentPastItsMonotonicExpiryIsAMissEvenThoughTheBackendKeptIt(): void
    {
        $expired = self::MARKER . json_encode(['c' => '<div>stale</div>', 'e' => hrtime(true) - 1]);

        $this->assertNull($this->decode($expired));
    }

    public function testAWrappedPayloadThatIsNotValidJsonIsAMiss(): void
    {
        $this->assertNull($this->decode(self::MARKER . 'not json at all'));
    }

    /** A wrapper missing either field, or carrying the wrong types, cannot be trusted. */
    public function testAWrappedPayloadOfTheWrongShapeIsAMiss(): void
    {
        $cases = [
            'no content' => ['e' => hrtime(true) + 1_000_000_000],
            'no expiry' => ['c' => 'content'],
            'content not a string' => ['c' => ['nested'], 'e' => hrtime(true) + 1_000_000_000],
            'expiry not an int' => ['c' => 'content', 'e' => 'soon'],
            'not an object' => ['just', 'a', 'list'],
        ];

        foreach ($cases as $name => $shape) {
            $this->assertNull($this->decode(self::MARKER . json_encode($shape)), $name . ' must be a miss');
        }
    }

    // --- exception reporting -----------------------------------------------

    public function testAShortTraceIsReportedInFull(): void
    {
        $trace = str_repeat('a', 100);
        $truncate = new ReflectionMethod(SlotDispatcher::class, 'truncateTrace');

        $this->assertSame($trace, $truncate->invoke($this->dispatcher(), $trace, 8000));
    }

    /**
     * A slot failing deep in a template can produce an enormous trace, and
     * the reporter writes to the error log -- so it is capped, and says that
     * it was.
     */
    public function testAnOversizedTraceIsCappedAndSaysSo(): void
    {
        $truncate = new ReflectionMethod(SlotDispatcher::class, 'truncateTrace');

        $result = $truncate->invoke($this->dispatcher(), str_repeat('a', 200), 50);

        $this->assertIsString($result);
        $this->assertStringEndsWith('... [truncated]', $result);
        $this->assertSame(50 + strlen('... [truncated]'), strlen($result));
    }

    /**
     * The slot's identity has to be in the record, or an error log full of
     * slot failures says nothing about which slot failed.
     */
    public function testAFailedSlotIsReportedWithItsIdentityAndPhase(): void
    {
        $log = sys_get_temp_dir() . '/quiote-slot-exception-' . bin2hex(random_bytes(6)) . '.log';
        $previous = ini_get('error_log');
        ini_set('error_log', $log);

        try {
            (new ReflectionMethod(SlotDispatcher::class, 'logSlotException'))->invoke(
                $this->dispatcher(),
                new RuntimeException('slot exploded'),
                'Blog',
                'Sidebar',
                ['tag' => 'php'],
                'deferred',
            );

            $written = is_file($log) ? (string) file_get_contents($log) : '';
            $this->assertStringContainsString('SLOT_EXCEPTION', $written);
            $this->assertStringContainsString('"module":"Blog"', $written);
            $this->assertStringContainsString('"action":"Sidebar"', $written);
            $this->assertStringContainsString('"phase":"deferred"', $written);
            $this->assertStringContainsString('slot exploded', $written);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }
    }
}
