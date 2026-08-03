<?php

use PHPUnit\Framework\TestCase;
use Quiote\Response\PsrResponseBuilder;

class PsrResponseBuilderTest extends TestCase
{
    public function testStatusAndHeadersAreApplied(): void
    {
        $response = (new PsrResponseBuilder())->build(
            201,
            ['Content-Type' => ['text/plain'], 'X-One' => 'a'],
            [],
            'body'
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertSame('a', $response->getHeaderLine('X-One'));
        $this->assertSame('body', (string) $response->getBody());
    }

    public function testEachCookieBecomesItsOwnSetCookieHeader(): void
    {
        $response = (new PsrResponseBuilder())->build(
            200,
            [],
            ['a=1; Path=/', 'b=2; Path=/'],
            ''
        );

        $this->assertSame(['a=1; Path=/', 'b=2; Path=/'], $response->getHeader('Set-Cookie'));
    }

    public function testWithBodyFalseEmitsHeadersOnly(): void
    {
        $response = (new PsrResponseBuilder())->build(
            302,
            ['Location' => 'https://example.test/'],
            [],
            'ignored',
            withBody: false
        );

        $this->assertSame('', (string) $response->getBody());
        $this->assertSame('https://example.test/', $response->getHeaderLine('Location'));
    }

    public function testNullContentBecomesAnEmptyBody(): void
    {
        $response = (new PsrResponseBuilder())->build(200, [], [], null);

        $this->assertSame('', (string) $response->getBody());
    }

    public function testScalarContentIsStringified(): void
    {
        $this->assertSame('42', (string) (new PsrResponseBuilder())->build(200, [], [], 42)->getBody());
        $this->assertSame('1', (string) (new PsrResponseBuilder())->build(200, [], [], true)->getBody());
    }

    public function testNonScalarContentBecomesAnEmptyBody(): void
    {
        $response = (new PsrResponseBuilder())->build(200, [], [], ['not', 'stringable']);

        $this->assertSame('', (string) $response->getBody());
    }

    public function testResourceContentIsWrappedAsAStream(): void
    {
        $handle = fopen('php://temp', 'r+');
        $this->assertNotFalse($handle);
        fwrite($handle, 'streamed');
        rewind($handle);

        $response = (new PsrResponseBuilder())->build(200, [], [], $handle);

        $this->assertSame('streamed', (string) $response->getBody());
    }

    /**
     * A plain-file body with sendfile enabled is handed to the front-end server by path,
     * with no body of our own.
     */
    public function testPlainFileResourceUsesTheSendfileHeader(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'quiote_sendfile_');
        $this->assertNotFalse($path);
        file_put_contents($path, 'on disk');
        $handle = fopen($path, 'r');
        $this->assertNotFalse($handle);

        try {
            $response = (new PsrResponseBuilder())->build(200, [], [], $handle, true, 'X-Sendfile');

            $this->assertSame($path, $response->getHeaderLine('X-Sendfile'));
            $this->assertSame('', (string) $response->getBody());
        } finally {
            fclose($handle);
            unlink($path);
        }
    }

    /**
     * A non-file stream has no path to hand over, so it is streamed normally even with the
     * sendfile header configured.
     */
    public function testNonFileResourceIsStreamedDespiteSendfileBeingEnabled(): void
    {
        $handle = fopen('php://temp', 'r+');
        $this->assertNotFalse($handle);
        fwrite($handle, 'in memory');
        rewind($handle);

        $response = (new PsrResponseBuilder())->build(200, [], [], $handle, true, 'X-Sendfile');

        $this->assertFalse($response->hasHeader('X-Sendfile'));
        $this->assertSame('in memory', (string) $response->getBody());
    }

    public function testSendfileIsSkippedForAnEmptyHeaderName(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'quiote_sendfile_');
        $this->assertNotFalse($path);
        file_put_contents($path, 'on disk');
        $handle = fopen($path, 'r');
        $this->assertNotFalse($handle);

        try {
            $response = (new PsrResponseBuilder())->build(200, [], [], $handle, true, '');

            $this->assertSame('on disk', (string) $response->getBody());
        } finally {
            fclose($handle);
            unlink($path);
        }
    }
}
