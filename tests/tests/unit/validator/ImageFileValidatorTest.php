<?php

use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Quiote\Exception\ConfigurationException;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ImageFileValidator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

/**
 * ImageFileValidator reads the uploaded file through the PSR-7 stream
 * (UploadedFileInterface has no getTmpName()); these tests exercise both
 * the image-detection success path and the misconfiguration/failure paths.
 */
class ImageFileValidatorTest extends UnitTestCase
{
	protected ValidationManager $vm;

	// A valid 1x1 pixel PNG.
	private const string PNG_1X1 = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90\x77\x53\xde\x00\x00\x00\x0cIDATx\x9cc\xf8\xcf\xc0\x00\x00\x03\x01\x01\x00\x18\xdd\x8d\xb0\x00\x00\x00\x00IEND\xaeB\x60\x82";

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

	private function uploadedFile(string $content, string $name = 'image.png', string $mime = 'image/png'): UploadedFile
	{
		$stream = Stream::create($content);
		return new UploadedFile($stream, $stream->getSize() ?? 0, UPLOAD_ERR_OK, $name, $mime);
	}

	public function testValidImagePassesAndReadsDimensions(): void
	{
		$validator = $this->vm->createValidator(ImageFileValidator::class, ['image'], [], []);
		$request = $this->requestWithFiles(['image' => $this->uploadedFile(self::PNG_1X1)]);
		$result = $validator->execute($request);
		$this->assertSame(Validator::SUCCESS, $result);
	}

	public function testNonImageUploadFailsWithNoImageError(): void
	{
		$errors = ['no_image' => $errorMsg = 'Not an image'];
		$validator = $this->vm->createValidator(ImageFileValidator::class, ['image'], $errors, []);
		$request = $this->requestWithFiles(['image' => $this->uploadedFile('not an image', 'notes.txt', 'text/plain')]);
		$result = $validator->execute($request);
		$this->assertSame(Validator::ERROR, $result);
		$this->assertSame([$errorMsg], $this->vm->getReport()->getErrorMessages());
	}

	public function testImageTooWideFailsMaxWidthCheck(): void
	{
		$errors = ['max_width' => $errorMsg = 'Too wide'];
		$validator = $this->vm->createValidator(ImageFileValidator::class, ['image'], $errors, ['max_width' => 0]);
		$request = $this->requestWithFiles(['image' => $this->uploadedFile(self::PNG_1X1)]);
		$result = $validator->execute($request);
		$this->assertSame(Validator::ERROR, $result);
		$this->assertSame([$errorMsg], $this->vm->getReport()->getErrorMessages());
	}

	public function testFormatAllowlistAcceptsMatchingFormat(): void
	{
		$validator = $this->vm->createValidator(ImageFileValidator::class, ['image'], [], ['format' => ['png', 'gif']]);
		$request = $this->requestWithFiles(['image' => $this->uploadedFile(self::PNG_1X1)]);
		$result = $validator->execute($request);
		$this->assertSame(Validator::SUCCESS, $result);
	}

	public function testFormatAllowlistRejectsNonMatchingFormat(): void
	{
		$errors = ['format' => $errorMsg = 'Wrong format'];
		$validator = $this->vm->createValidator(ImageFileValidator::class, ['image'], $errors, ['format' => ['gif']]);
		$request = $this->requestWithFiles(['image' => $this->uploadedFile(self::PNG_1X1)]);
		$result = $validator->execute($request);
		$this->assertSame(Validator::ERROR, $result);
		$this->assertSame([$errorMsg], $this->vm->getReport()->getErrorMessages());
	}

	/**
	 * "format" is a validator configuration value; a non-array, non-string
	 * value is a misconfiguration and must fail loudly.
	 */
	public function testNonArrayNonStringFormatParameterThrows(): void
	{
		$this->expectException(ConfigurationException::class);
		$validator = $this->vm->createValidator(ImageFileValidator::class, ['image'], [], ['format' => 42]);
		$request = $this->requestWithFiles(['image' => $this->uploadedFile(self::PNG_1X1)]);
		$validator->execute($request);
	}
}

?>
