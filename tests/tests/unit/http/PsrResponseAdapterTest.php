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
}
