<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Http\MimeTypeRegistry;

class MimeTypeRegistryTest extends TestCase
{
    // --- primaryMimeType ---

    public function testPrimaryMimeTypeJson(): void
    {
        $this->assertSame('application/json; charset=UTF-8', MimeTypeRegistry::primaryMimeType('json'));
    }

    public function testPrimaryMimeTypeHtml(): void
    {
        $this->assertSame('text/html; charset=UTF-8', MimeTypeRegistry::primaryMimeType('html'));
    }

    public function testPrimaryMimeTypeXml(): void
    {
        // symfony/mime returns application/xml as the primary for the 'xml' extension
        $this->assertSame('application/xml; charset=UTF-8', MimeTypeRegistry::primaryMimeType('xml'));
    }

    public function testPrimaryMimeTypePng(): void
    {
        $this->assertSame('image/png', MimeTypeRegistry::primaryMimeType('png'));
    }

    public function testPrimaryMimeTypeModernOffice(): void
    {
        $this->assertStringContainsString(
            'spreadsheetml',
            MimeTypeRegistry::primaryMimeType('xlsx') ?? ''
        );
        $this->assertStringContainsString(
            'wordprocessingml',
            MimeTypeRegistry::primaryMimeType('docx') ?? ''
        );
        $this->assertStringContainsString(
            'presentationml',
            MimeTypeRegistry::primaryMimeType('pptx') ?? ''
        );
    }

    public function testPrimaryMimeTypeCsv(): void
    {
        $this->assertSame('text/csv; charset=UTF-8', MimeTypeRegistry::primaryMimeType('csv'));
    }

    public function testPrimaryMimeTypeUnknownReturnsNull(): void
    {
        $this->assertNull(MimeTypeRegistry::primaryMimeType('totally_unknown_format'));
    }

    // --- formatsForMime ---

    public function testFormatsForMimeApplicationJson(): void
    {
        $this->assertSame(['json'], MimeTypeRegistry::formatsForMime('application/json'));
    }

    public function testFormatsForMimeTextHtml(): void
    {
        $this->assertSame(['html'], MimeTypeRegistry::formatsForMime('text/html'));
    }

    public function testFormatsForMimeApplicationXhtml(): void
    {
        $this->assertSame(['html'], MimeTypeRegistry::formatsForMime('application/xhtml+xml'));
    }

    public function testFormatsForMimeImagePng(): void
    {
        $this->assertSame(['png'], MimeTypeRegistry::formatsForMime('image/png'));
    }

    public function testFormatsForMimeUnknownReturnsEmpty(): void
    {
        $this->assertSame([], MimeTypeRegistry::formatsForMime('application/vnd.totally-unknown'));
    }

    public function testFormatsForMimeTextJsonNotRecognised(): void
    {
        // text/json is not a real MIME type; application/json is correct
        $this->assertSame([], MimeTypeRegistry::formatsForMime('text/json'));
    }

    // --- formatForMime ---

    public function testFormatForMimeApplicationJson(): void
    {
        $this->assertSame('json', MimeTypeRegistry::formatForMime('application/json'));
    }

    public function testFormatForMimeTextHtml(): void
    {
        $this->assertSame('html', MimeTypeRegistry::formatForMime('text/html'));
    }

    public function testFormatForMimeApplicationXhtml(): void
    {
        $this->assertSame('html', MimeTypeRegistry::formatForMime('application/xhtml+xml'));
    }

    public function testFormatForMimeImagePng(): void
    {
        $this->assertSame('png', MimeTypeRegistry::formatForMime('image/png'));
    }

    public function testFormatForMimeUnknownReturnsNull(): void
    {
        $this->assertNull(MimeTypeRegistry::formatForMime('application/vnd.totally-unknown'));
    }

    // --- formatForExtension ---

    public function testFormatForExtensionJson(): void
    {
        $this->assertSame('json', MimeTypeRegistry::formatForExtension('json'));
    }

    public function testFormatForExtensionHtm(): void
    {
        $this->assertSame('html', MimeTypeRegistry::formatForExtension('htm'));
    }

    public function testFormatForExtensionJpeg(): void
    {
        $this->assertSame('jpg', MimeTypeRegistry::formatForExtension('jpeg'));
    }

    public function testFormatForExtensionXhtml(): void
    {
        $this->assertSame('html', MimeTypeRegistry::formatForExtension('xhtml'));
    }

    public function testFormatForExtensionUppercaseNormalized(): void
    {
        $this->assertSame('json', MimeTypeRegistry::formatForExtension('JSON'));
    }

    public function testFormatForExtensionUnknownReturnsNull(): void
    {
        $this->assertNull(MimeTypeRegistry::formatForExtension('totally_unknown_ext'));
    }

    // --- allMimeTypes ---

    public function testAllMimeTypesIsNonEmpty(): void
    {
        $all = MimeTypeRegistry::allMimeTypes();
        $this->assertNotEmpty($all);
    }

    public function testAllMimeTypesContainsCommonTypes(): void
    {
        $all = MimeTypeRegistry::allMimeTypes();
        $this->assertContains('application/json', $all);
        $this->assertContains('text/html', $all);
        $this->assertContains('image/png', $all);
        $this->assertContains('application/pdf', $all);
    }

    public function testAllMimeTypesContainsModernOfficeTypes(): void
    {
        $all = MimeTypeRegistry::allMimeTypes();
        $this->assertContains('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $all);
        $this->assertContains('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $all);
    }

    public function testAllMimeTypesDoesNotContainTextJson(): void
    {
        $this->assertNotContains('text/json', MimeTypeRegistry::allMimeTypes());
    }

    public function testAllMimeTypesAreStrings(): void
    {
        foreach (MimeTypeRegistry::allMimeTypes() as $mime) {
            $this->assertNotSame('', $mime);
        }
    }

    // --- roundtrip consistency ---

    public function testPrimaryMimeTypeRoundtripsViaFormatForMime(): void
    {
        $formats = ['html', 'json', 'xml', 'png', 'jpg', 'pdf', 'csv', 'js'];
        foreach ($formats as $format) {
            $primary = MimeTypeRegistry::primaryMimeType($format);
            $this->assertNotNull($primary, "Format '$format' should have a primary MIME type");
            $mime = explode(';', $primary)[0]; // strip '; charset=UTF-8'
            $this->assertSame(
                $format,
                MimeTypeRegistry::formatForMime($mime),
                "Primary MIME '$mime' should round-trip back to format '$format'"
            );
        }
    }
}
