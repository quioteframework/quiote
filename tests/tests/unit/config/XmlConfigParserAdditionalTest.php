<?php

use Quiote\Config\XmlConfigParser;
use Quiote\Exception\ParseException;
use Quiote\Exception\UnreadableException;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Happy + failure path coverage for XmlConfigParser gaps not already exercised
 * by the config-handler suites: the namespace helper statics, constructor
 * error paths, and the XML Schema / RELAX NG validation entry points.
 */
class XmlConfigParserAdditionalTest extends PhpUnitTestCase
{
    /** @var list<string> */
    private array $filesToDelete = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->filesToDelete as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    private function tempFile(string $suffix, string $contents): string
    {
        $path = sprintf('%s/xcp-%s%s', sys_get_temp_dir(), bin2hex(random_bytes(8)), $suffix);
        file_put_contents($path, $contents);
        $this->filesToDelete[] = $path;
        return $path;
    }

    public function testIsQuioteNamespaceRecognizesKnownNamespaces(): void
    {
        $this->assertTrue(XmlConfigParser::isQuioteNamespace(XmlConfigParser::NAMESPACE_QUIOTE_ENVELOPE_1_1));
        $this->assertTrue(XmlConfigParser::isQuioteNamespace(XmlConfigParser::NAMESPACE_QUIOTE_ANNOTATIONS_1_0));
    }

    public function testIsQuioteNamespaceRejectsUnknownNamespace(): void
    {
        $this->assertFalse(XmlConfigParser::isQuioteNamespace('http://example.com/not-a-quiote-namespace'));
    }

    public function testGetQuioteNamespacePrefixReturnsPrefixForKnownNamespace(): void
    {
        $this->assertSame('quiote_envelope_1_1', XmlConfigParser::getQuioteNamespacePrefix(XmlConfigParser::NAMESPACE_QUIOTE_ENVELOPE_1_1));
    }

    public function testGetQuioteNamespacePrefixReturnsNullForUnknownNamespace(): void
    {
        $this->assertNull(XmlConfigParser::getQuioteNamespacePrefix('http://example.com/not-a-quiote-namespace'));
    }

    public function testIsLegacyEnvelopeNamespaceRecognizesDroppedNamespaces(): void
    {
        $this->assertTrue(XmlConfigParser::isLegacyEnvelopeNamespace('http://quiote.org/quiote/1.0/config'));
        $this->assertTrue(XmlConfigParser::isLegacyEnvelopeNamespace('http://quiote.dev/quiote/config/global/envelope/1.0'));
    }

    public function testIsLegacyEnvelopeNamespaceRejectsCurrentAndUnknownNamespaces(): void
    {
        $this->assertFalse(XmlConfigParser::isLegacyEnvelopeNamespace(XmlConfigParser::NAMESPACE_QUIOTE_ENVELOPE_1_1));
        $this->assertFalse(XmlConfigParser::isLegacyEnvelopeNamespace('http://example.com/not-a-quiote-namespace'));
    }

    public function testRunThrowsClearParseExceptionForLegacy011EnvelopeFile(): void
    {
        $file = $this->tempFile('.xml', '<configurations xmlns="http://quiote.org/quiote/1.0/config"><configuration><settings/></configuration></configurations>');

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('unsupported legacy Quiote envelope namespace "http://quiote.org/quiote/1.0/config"');
        XmlConfigParser::run($file, 'testing');
    }

    public function testRunThrowsClearParseExceptionForLegacy10EnvelopeFile(): void
    {
        $file = $this->tempFile('.xml', '<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.0"><ae:configuration/></ae:configurations>');

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('unsupported legacy Quiote envelope namespace "http://quiote.dev/quiote/config/global/envelope/1.0"');
        XmlConfigParser::run($file, 'testing');
    }

    public function testRunAcceptsCurrentEnvelope11File(): void
    {
        $file = $this->tempFile('.xml', '<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"><ae:configuration/></ae:configurations>');

        $doc = XmlConfigParser::run($file, 'testing');
        $this->assertNotNull($doc->documentElement);
        $this->assertSame(XmlConfigParser::NAMESPACE_QUIOTE_ENVELOPE_1_1, $doc->documentElement->namespaceURI);
    }

    public function testRunPassesThroughForeignNonQuioteXmlWithoutError(): void
    {
        $file = $this->tempFile('.xml', '<root xmlns="http://example.com/some-foreign-namespace"><child/></root>');

        $doc = XmlConfigParser::run($file, 'testing');
        $this->assertNotNull($doc->documentElement);
        $this->assertSame('http://example.com/some-foreign-namespace', $doc->documentElement->namespaceURI);
    }

    public function testConstructorThrowsOnUnreadableFile(): void
    {
        $missing = sys_get_temp_dir() . '/xcp-does-not-exist-' . bin2hex(random_bytes(8)) . '.xml';
        $this->expectException(UnreadableException::class);
        new XmlConfigParser($missing, 'testing');
    }

    public function testConstructorThrowsParseExceptionOnMalformedXml(): void
    {
        $file = $this->tempFile('.xml', '<root><unclosed></root>');
        $this->expectException(ParseException::class);
        new XmlConfigParser($file, 'testing');
    }

    public function testValidateXsiWithNoNamespaceSchemaLocationSucceeds(): void
    {
        $xsd = $this->tempFile('.xsd', <<<'XSD'
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="root" type="xs:string"/>
</xs:schema>
XSD);
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml(sprintf(
            '<root xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="%s">hello</root>',
            $xsd
        ));

        XmlConfigParser::validateXsi($doc);
        $this->addToAssertionCount(1);
    }

    public function testValidateXsiWithUnreadableSchemaLocationThrows(): void
    {
        $missing = sys_get_temp_dir() . '/xcp-does-not-exist-' . bin2hex(random_bytes(8)) . '.xsd';
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml(sprintf(
            '<root xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="%s">hello</root>',
            $missing
        ));

        $this->expectException(UnreadableException::class);
        XmlConfigParser::validateXsi($doc);
    }

    public function testValidateXsiWithoutSchemaAttributesIsANoOp(): void
    {
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml('<root>hello</root>');

        XmlConfigParser::validateXsi($doc);
        $this->addToAssertionCount(1);
    }

    public function testValidateXmlschemaWithUnreadableFileThrows(): void
    {
        $missing = sys_get_temp_dir() . '/xcp-does-not-exist-' . bin2hex(random_bytes(8)) . '.xsd';
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml('<root>hello</root>');

        $this->expectException(UnreadableException::class);
        XmlConfigParser::validateXmlschema($doc, [$missing]);
    }

    public function testValidateXmlschemaAgainstValidDocumentSucceeds(): void
    {
        $xsd = $this->tempFile('.xsd', <<<'XSD'
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="root" type="xs:string"/>
</xs:schema>
XSD);
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml('<root>hello</root>');

        XmlConfigParser::validateXmlschema($doc, [$xsd]);
        $this->addToAssertionCount(1);
    }

    public function testValidateXmlschemaAgainstInvalidDocumentThrowsParseException(): void
    {
        $xsd = $this->tempFile('.xsd', <<<'XSD'
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="root">
    <xs:complexType>
      <xs:sequence>
        <xs:element name="required-child" type="xs:string"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
</xs:schema>
XSD);
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml('<root><wrong-child>hello</wrong-child></root>');

        $this->expectException(ParseException::class);
        XmlConfigParser::validateXmlschema($doc, [$xsd]);
    }

    public function testValidateXmlschemaSourceAgainstValidDocumentSucceeds(): void
    {
        $xsdSource = <<<'XSD'
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="root" type="xs:string"/>
</xs:schema>
XSD;
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml('<root>hello</root>');

        XmlConfigParser::validateXmlschemaSource($doc, [$xsdSource]);
        $this->addToAssertionCount(1);
    }

    public function testValidateXmlschemaSourceAgainstInvalidDocumentThrowsParseException(): void
    {
        $xsdSource = <<<'XSD'
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="root">
    <xs:complexType>
      <xs:sequence>
        <xs:element name="required-child" type="xs:string"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
</xs:schema>
XSD;
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml('<root><wrong-child>hello</wrong-child></root>');

        $this->expectException(ParseException::class);
        XmlConfigParser::validateXmlschemaSource($doc, [$xsdSource]);
    }

    public function testValidateRelaxngWithUnreadableFileThrows(): void
    {
        $missing = sys_get_temp_dir() . '/xcp-does-not-exist-' . bin2hex(random_bytes(8)) . '.rng';
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml('<root>hello</root>');

        $this->expectException(UnreadableException::class);
        XmlConfigParser::validateRelaxng($doc, [$missing]);
    }

    public function testValidateRelaxngAgainstValidDocumentSucceeds(): void
    {
        $rng = $this->tempFile('.rng', <<<'RNG'
<?xml version="1.0"?>
<element name="root" xmlns="http://relaxng.org/ns/structure/1.0">
  <text/>
</element>
RNG);
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml('<root>hello</root>');

        XmlConfigParser::validateRelaxng($doc, [$rng]);
        $this->addToAssertionCount(1);
    }

    public function testValidateRelaxngAgainstInvalidDocumentThrowsParseException(): void
    {
        $rng = $this->tempFile('.rng', <<<'RNG'
<?xml version="1.0"?>
<element name="root" xmlns="http://relaxng.org/ns/structure/1.0">
  <element name="required-child">
    <text/>
  </element>
</element>
RNG);
        $doc = new \Quiote\Config\Util\DOM\XmlConfigDomDocument();
        $doc->loadXml('<root><wrong-child>hello</wrong-child></root>');

        $this->expectException(ParseException::class);
        XmlConfigParser::validateRelaxng($doc, [$rng]);
    }
}
