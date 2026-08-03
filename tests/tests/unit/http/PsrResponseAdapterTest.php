<?php

use PHPUnit\Framework\TestCase;
use Quiote\Http\PsrResponseAdapter;
use Quiote\Response\WebResponse;

class PsrResponseAdapterTest extends TestCase
{
    public function testGetBodyWithStringContentReturnsStreamWithSameContents(): void
    {
        $legacy = new WebResponse();
        $legacy->setContent('hello world');

        $adapter = new PsrResponseAdapter($legacy);

        $this->assertSame('hello world', (string) $adapter->getBody());
    }

    public function testGetBodyWithNullContentReturnsEmptyStream(): void
    {
        $legacy = new WebResponse();

        $adapter = new PsrResponseAdapter($legacy);

        $this->assertSame('', (string) $adapter->getBody());
    }

    public function testGetBodyWithIntContentIsStringified(): void
    {
        $legacy = new WebResponse();
        $legacy->setContent(42);

        $adapter = new PsrResponseAdapter($legacy);

        $this->assertSame('42', (string) $adapter->getBody());
    }

    public function testGetBodyWithResourceContentWrapsResourceDirectly(): void
    {
        $resource = fopen('php://temp', 'r+');
        $this->assertNotFalse($resource);
        fwrite($resource, 'stream body');
        rewind($resource);

        $legacy = new WebResponse();
        $legacy->setContent($resource);

        $adapter = new PsrResponseAdapter($legacy);

        $this->assertSame('stream body', (string) $adapter->getBody());
    }

    public function testGetBodyIsMemoizedAcrossCalls(): void
    {
        $legacy = new WebResponse();
        $legacy->setContent('first');

        $adapter = new PsrResponseAdapter($legacy);

        $first = $adapter->getBody();
        $second = $adapter->getBody();

        $this->assertSame($first, $second);
    }

    public function testGetBodyWithUnsupportedContentTypeThrows(): void
    {
        $legacy = new WebResponse();
        $legacy->setContent(new stdClass());

        $adapter = new PsrResponseAdapter($legacy);

        $this->expectException(RuntimeException::class);
        $adapter->getBody();
    }

    public function testReadsStatusAndHeadersThroughToTheWebResponse(): void
    {
        $legacy = new WebResponse();
        $legacy->setHttpStatusCode(404);
        $legacy->setHttpHeader('X-Read', 'through');

        $adapter = new PsrResponseAdapter($legacy);

        $this->assertSame(404, $adapter->getStatusCode());
        $this->assertSame('Not Found', $adapter->getReasonPhrase());
        $this->assertSame('through', $adapter->getHeaderLine('X-Read'));
        $this->assertTrue($adapter->hasHeader('x-read'));
    }

    public function testWithStatusReturnsANewInstanceAndLeavesBothTheAdapterAndResponseAlone(): void
    {
        $legacy = new WebResponse();
        $legacy->setHttpStatusCode(200);
        $adapter = new PsrResponseAdapter($legacy);

        $changed = $adapter->withStatus(422);

        $this->assertNotSame($adapter, $changed);
        $this->assertSame(422, $changed->getStatusCode());
        $this->assertSame(200, $adapter->getStatusCode());
        $this->assertEquals('200', $legacy->getHttpStatusCode());
    }

    public function testWithStatusRejectsAnInvalidCodeWithThePsrMandatedException(): void
    {
        $adapter = new PsrResponseAdapter(new WebResponse());

        $this->expectException(InvalidArgumentException::class);
        $adapter->withStatus(999);
    }

    public function testWithStatusCarriesAnExplicitReasonPhrase(): void
    {
        $adapter = (new PsrResponseAdapter(new WebResponse()))->withStatus(418, 'Short And Stout');

        $this->assertSame('Short And Stout', $adapter->getReasonPhrase());
    }

    public function testWithHeaderReturnsANewInstanceAndLeavesTheResponseAlone(): void
    {
        $legacy = new WebResponse();
        $adapter = new PsrResponseAdapter($legacy);

        $changed = $adapter->withHeader('X-New', 'value');

        $this->assertNotSame($adapter, $changed);
        $this->assertSame('value', $changed->getHeaderLine('X-New'));
        $this->assertFalse($adapter->hasHeader('X-New'));
        $this->assertFalse($legacy->hasHttpHeader('X-New'));
    }

    public function testWithHeaderReplacesCaseInsensitively(): void
    {
        $legacy = new WebResponse();
        $legacy->setHttpHeader('X-Thing', 'first');

        $changed = (new PsrResponseAdapter($legacy))->withHeader('x-thing', 'second');

        $this->assertSame(['second'], $changed->getHeader('X-Thing'));
    }

    public function testWithAddedHeaderAppendsToTheExistingValues(): void
    {
        $legacy = new WebResponse();
        $legacy->setHttpHeader('X-Multi', 'one');

        $changed = (new PsrResponseAdapter($legacy))->withAddedHeader('x-multi', 'two');

        $this->assertSame(['one', 'two'], $changed->getHeader('X-Multi'));
    }

    public function testWithoutHeaderRemovesCaseInsensitively(): void
    {
        $legacy = new WebResponse();
        $legacy->setHttpHeader('X-Gone', 'value');
        $adapter = new PsrResponseAdapter($legacy);

        $changed = $adapter->withoutHeader('x-gone');

        $this->assertFalse($changed->hasHeader('X-Gone'));
        $this->assertTrue($adapter->hasHeader('X-Gone'));
    }

    public function testWithoutHeaderForAnAbsentNameReturnsTheSameInstance(): void
    {
        $adapter = new PsrResponseAdapter(new WebResponse());

        $this->assertSame($adapter, $adapter->withoutHeader('X-Never-Set'));
    }

    public function testWithBodyReturnsANewInstance(): void
    {
        $legacy = new WebResponse();
        $legacy->setContent('original');
        $adapter = new PsrResponseAdapter($legacy);

        $changed = $adapter->withBody(\Quiote\Http\SimpleStream::fromString('replaced'));

        $this->assertNotSame($adapter, $changed);
        $this->assertSame('replaced', (string) $changed->getBody());
        $this->assertSame('original', (string) $adapter->getBody());
    }

    public function testWithProtocolVersionReturnsANewInstance(): void
    {
        $adapter = new PsrResponseAdapter(new WebResponse());

        $changed = $adapter->withProtocolVersion('2');

        $this->assertSame('2', $changed->getProtocolVersion());
        $this->assertSame('1.1', $adapter->getProtocolVersion());
    }

    /**
     * Writing to the response the framework will send is done through the WebResponse, which
     * the adapter still exposes for exactly that purpose.
     */
    public function testGetLegacyExposesTheMutableResponse(): void
    {
        $legacy = new WebResponse();
        $adapter = new PsrResponseAdapter($legacy);

        $adapter->getLegacy()->setHttpHeader('X-Direct', 'written');

        $this->assertSame('written', $adapter->getHeaderLine('X-Direct'));
        $this->assertTrue($legacy->hasHttpHeader('X-Direct'));
    }
}
