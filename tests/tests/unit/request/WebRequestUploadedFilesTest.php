<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Request\WebRequest;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Focused tests for uploaded files handling in WebRequest.
 * Covers nested arrays, multiple files for same field, error codes, empty uploads, and deep nesting pruning safety.
 */
class WebRequestUploadedFilesTest extends UnitTestCase
{
    /** @param array<string, mixed> $files */
    private function newRequest(array $files): WebRequest
    {
        $wr = new WebRequest('POST', 'http://example.test/upload');
        $wr->initialize($this->getContext());
        $wr = $wr->withUploadedFiles($files);
        return $wr;
    }

    private function file(string $name, string $mime = 'text/plain', string $content = ''): UploadedFileInterface
    {
        $stream = Stream::create($content);
        return new UploadedFile($stream, $stream->getSize() ?? 0, UPLOAD_ERR_OK, $name, $mime);
    }

    /** Narrows a getUploadedFiles() leaf to a single UploadedFileInterface. */
    private function asFile(mixed $node): UploadedFileInterface
    {
        $this->assertInstanceOf(UploadedFileInterface::class, $node);
        return $node;
    }

    /**
     * Narrows a getUploadedFiles() node to a nested array.
     * @return array<array-key, mixed>
     */
    private function asFileArray(mixed $node): array
    {
        $this->assertIsArray($node);
        return $node;
    }

    public function testSingleFileSimpleKey(): void
    {
        $wr = $this->newRequest(['doc' => $this->file('readme.txt')]);
        $files = $wr->getUploadedFiles();
        $this->assertArrayHasKey('doc', $files);
        $this->assertInstanceOf(UploadedFileInterface::class, $files['doc']);
    }

    public function testMultipleFilesSameField(): void
    {
        $wr = $this->newRequest([
            'photos' => [
                $this->file('a.jpg', 'image/jpeg','a'),
                $this->file('b.jpg', 'image/jpeg','b'),
            ],
        ]);
        $files = $wr->getUploadedFiles();
        $photos = $this->asFileArray($files['photos']);
        $this->assertCount(2, $photos);
        $this->assertSame('a.jpg', $this->asFile($photos[0])->getClientFilename());
    }

    public function testNestedAssociativeArray(): void
    {
        $wr = $this->newRequest([
            'attachments' => [
                'invoices' => [
                    $this->file('jan.pdf','application/pdf','jan'),
                    $this->file('feb.pdf','application/pdf','feb'),
                ],
                'images' => [
                    'cover' => $this->file('cover.png','image/png','cov'),
                ],
            ],
        ]);
        $files = $wr->getUploadedFiles();
        $this->assertArrayHasKey('attachments', $files);
        $attachments = $this->asFileArray($files['attachments']);
        $this->assertArrayHasKey('invoices', $attachments);
        $invoices = $this->asFileArray($attachments['invoices']);
        $this->assertInstanceOf(UploadedFileInterface::class, $this->asFile($invoices[0]));
        $images = $this->asFileArray($attachments['images']);
        $this->assertSame('cover.png', $this->asFile($images['cover'])->getClientFilename());
    }

    public function testUploadErrorCodesPreserved(): void
    {
        $stream = Stream::create('ignored');
        $errFile = new UploadedFile($stream, $stream->getSize() ?? 7, UPLOAD_ERR_PARTIAL, 'partial.bin', 'application/octet-stream');
        $wr = $this->newRequest(['bin' => $errFile]);
        $files = $wr->getUploadedFiles();
        $this->assertSame(UPLOAD_ERR_PARTIAL, $this->asFile($files['bin'])->getError());
    }

    public function testEmptyFileEntryZeroSize(): void
    {
        $empty = new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_OK, 'empty.dat', 'application/octet-stream');
        $wr = $this->newRequest(['empty' => $empty]);
        $this->assertSame(0, $this->asFile($wr->getUploadedFiles()['empty'])->getSize());
    }

    public function testDeeplyNestedStructure(): void
    {
        $wr = $this->newRequest([
            'level1' => [ 'level2' => [ 'level3' => [ 'f' => $this->file('deep.txt','text/plain','d') ] ] ]
        ]);
        $files = $wr->getUploadedFiles();
        $level2 = $this->asFileArray($files['level1']);
        $level3 = $this->asFileArray($level2['level2']);
        $leaf = $this->asFileArray($level3['level3']);
        $this->assertSame('deep.txt', $this->asFile($leaf['f'])->getClientFilename());
    }
}
