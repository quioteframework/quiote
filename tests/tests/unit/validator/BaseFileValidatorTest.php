<?php

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Quiote\Exception\ConfigurationException;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\FileValidator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

/**
 * FileValidator is the concrete, instantiable BaseFileValidator subclass
 * used to exercise the shared extension/mime_type parameter handling in
 * BaseFileValidator::validate().
 */
class BaseFileValidatorTest extends UnitTestCase
{
	protected ValidationManager $vm;

	#[\Override]
    public function setUp(): void
	{
		$this->vm = $this->getContext()->getContainer()->get(\Quiote\Validator\ValidationManager::class);
	}

	/** @param array<string, array<int|string, mixed>|\Psr\Http\Message\UploadedFileInterface> $files */
	private function requestWithFiles(array $files): WebRequest
	{
		$wr = new WebRequest('POST', 'http://example.test/upload');
		$wr->initialize($this->getContext());
		return $wr->withUploadedFiles($files);
	}

	private function uploadedFile(string $content, string $name, string $mime): UploadedFile
	{
		$stream = Stream::create($content);
		return new UploadedFile($stream, $stream->getSize() ?? 0, UPLOAD_ERR_OK, $name, $mime);
	}

	public function testExtensionAsArrayAcceptsMatchingUpload(): void
	{
		$validator = $this->vm->createValidator(FileValidator::class, ['doc'], [], ['extension' => ['txt', 'csv']]);
		$request = $this->requestWithFiles(['doc' => $this->uploadedFile('hello', 'readme.txt', 'text/plain')]);
		$result = $validator->execute($request);
		$this->assertSame(Validator::SUCCESS, $result);
	}

	public function testExtensionAsSpaceSeparatedStringAcceptsMatchingUpload(): void
	{
		$validator = $this->vm->createValidator(FileValidator::class, ['doc'], [], ['extension' => 'txt csv']);
		$request = $this->requestWithFiles(['doc' => $this->uploadedFile('hello', 'readme.txt', 'text/plain')]);
		$result = $validator->execute($request);
		$this->assertSame(Validator::SUCCESS, $result);
	}

	public function testExtensionRejectsNonMatchingUpload(): void
	{
		$errors = ['extension' => $errorMsg = 'Wrong extension'];
		$validator = $this->vm->createValidator(FileValidator::class, ['doc'], $errors, ['extension' => 'csv']);
		$request = $this->requestWithFiles(['doc' => $this->uploadedFile('hello', 'readme.txt', 'text/plain')]);
		$result = $validator->execute($request);
		$this->assertSame(Validator::ERROR, $result);
		$this->assertSame([$errorMsg], $this->vm->getReport()->getErrorMessages());
	}

	/**
	 * "extension" is a validator configuration value; a non-array,
	 * non-string value is a misconfiguration.
	 */
	public function testNonArrayNonStringExtensionParameterThrows(): void
	{
		$this->expectException(ConfigurationException::class);
		$validator = $this->vm->createValidator(FileValidator::class, ['doc'], [], ['extension' => 42]);
		$request = $this->requestWithFiles(['doc' => $this->uploadedFile('hello', 'readme.txt', 'text/plain')]);
		$validator->execute($request);
	}

	public function testMimeTypeRegexAcceptsMatchingUpload(): void
	{
		$validator = $this->vm->createValidator(FileValidator::class, ['doc'], [], ['mime_type' => '#^text/#']);
		$request = $this->requestWithFiles(['doc' => $this->uploadedFile('hello world', 'readme.txt', 'text/plain')]);
		$result = $validator->execute($request);
		$this->assertSame(Validator::SUCCESS, $result);
	}

	/**
	 * "mime_type" is a validator configuration value (a PCRE pattern); a
	 * non-string value is a misconfiguration and must fail loudly instead
	 * of reaching preg_match() with the wrong type.
	 */
	public function testNonStringMimeTypeParameterThrows(): void
	{
		$this->expectException(ConfigurationException::class);
		$validator = $this->vm->createValidator(FileValidator::class, ['doc'], [], ['mime_type' => ['#^text/#']]);
		$request = $this->requestWithFiles(['doc' => $this->uploadedFile('hello world', 'readme.txt', 'text/plain')]);
		$validator->execute($request);
	}
}

?>
