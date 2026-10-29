<?php

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\FileValidator;
use Quiote\Validator\StringValidator;
use Quiote\Validator\ValidationManager;

/**
 * Uploaded files and cookies are pruned per path, the same way request
 * parameters are: a nested file or cookie that a validator vouched for
 * reaches the action, and its unvalidated or failed siblings do not.
 */
class NestedExtendedSourcePruningTest extends UnitTestCase
{
    private function manager(): ValidationManager
    {
        $context = $this->getContext();
        $manager = $context->getContainer()->get(ValidationManager::class);
        $manager->initialize($context, ['mode' => ValidationManager::MODE_STRICT]);

        return $manager;
    }

    private function upload(string $name): UploadedFile
    {
        $stream = Stream::create('content of ' . $name);

        return new UploadedFile($stream, $stream->getSize() ?? 0, UPLOAD_ERR_OK, $name, 'text/plain');
    }

    /**
     * @param array<string, array<int|string, mixed>|UploadedFileInterface> $files
     * @param array<string, mixed> $cookies
     */
    private function validate(ValidationManager $manager, array $files, array $cookies = []): WebRequest
    {
        $request = new WebRequest('POST', 'http://example.test/upload');
        $request->initialize($this->getContext());
        $manager->execute($request->withUploadedFiles($files)->withCookieParams($cookies));

        return $this->getContext()->getContainer()->get(WebRequest::class);
    }

    public function testNestedFilesUnderAnArrayBaseSurvive(): void
    {
        $manager = $this->manager();
        $manager->createValidator(FileValidator::class, ['Attachment'], [], ['base' => 'rows[]', 'extension' => 'txt']);
        $first = $this->upload('a.txt');
        $second = $this->upload('b.txt');

        $files = $this->validate($manager, ['rows' => [['Attachment' => $first], ['Attachment' => $second]]])->getUploadedFiles();

        $this->assertSame(['rows' => [['Attachment' => $first], ['Attachment' => $second]]], $files);
    }

    public function testElementsOfAFileListSurvive(): void
    {
        $manager = $this->manager();
        $manager->createValidator(FileValidator::class, [''], [], ['base' => 'attachments[]', 'extension' => 'txt']);
        $first = $this->upload('a.txt');
        $second = $this->upload('b.txt');

        $files = $this->validate($manager, ['attachments' => [$first, $second]])->getUploadedFiles();

        $this->assertSame(['attachments' => [$first, $second]], $files);
    }

    public function testUnvalidatedNestedFileIsDropped(): void
    {
        $manager = $this->manager();
        $manager->createValidator(FileValidator::class, ['Attachment'], [], ['base' => 'rows[]', 'extension' => 'txt']);
        $kept = $this->upload('a.txt');

        $files = $this->validate($manager, ['rows' => [['Attachment' => $kept, 'Smuggled' => $this->upload('x.php')]]])->getUploadedFiles();

        $this->assertSame(['rows' => [['Attachment' => $kept]]], $files);
    }

    public function testFailedNestedFileIsDroppedWhileItsSiblingStays(): void
    {
        $manager = $this->manager();
        $manager->createValidator(FileValidator::class, ['Attachment'], [], ['base' => 'rows[]', 'extension' => 'txt']);
        $kept = $this->upload('a.txt');

        $files = $this->validate($manager, ['rows' => [['Attachment' => $kept], ['Attachment' => $this->upload('b.exe')]]])->getUploadedFiles();

        $this->assertSame(['rows' => [['Attachment' => $kept]]], $files);
    }

    public function testTopLevelFileStillSurvivesAndUnvalidatedOnesStillGo(): void
    {
        $manager = $this->manager();
        $manager->createValidator(FileValidator::class, ['doc'], [], ['extension' => 'txt']);
        $doc = $this->upload('readme.txt');

        $files = $this->validate($manager, ['doc' => $doc, 'other' => $this->upload('other.txt')])->getUploadedFiles();

        $this->assertSame(['doc' => $doc], $files);
    }

    public function testNestedCookieSurvivesAndItsUnvalidatedSiblingGoes(): void
    {
        $manager = $this->manager();
        $manager->createValidator(StringValidator::class, ['theme'], [], ['base' => 'prefs', 'source' => 'cookies']);

        $cookies = $this->validate($manager, [], ['prefs' => ['theme' => 'dark', 'tracking' => 'on'], 'stray' => '1'])->getCookieParams();

        $this->assertSame(['prefs' => ['theme' => 'dark']], $cookies);
    }
}
