<?php

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Happy + failure path coverage for XmlConfigDomDocument, whose load/loadXml/
 * xinclude/importNode/schemaValidate/schemaValidateSource/relaxNGValidate all
 * follow the same "wrap parent::, collect libxml errors, throw DOMException" shape.
 */
class XmlConfigDomDocumentTest extends PhpUnitTestCase
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
        $path = sprintf('%s/xcdd-%s%s', sys_get_temp_dir(), bin2hex(random_bytes(8)), $suffix);
        file_put_contents($path, $contents);
        $this->filesToDelete[] = $path;
        return $path;
    }

    private function documentElement(XmlConfigDomDocument $doc): DOMElement
    {
        $element = $doc->documentElement;
        if ($element === null) {
            throw new \RuntimeException('Expected a document element.');
        }
        return $element;
    }

    public function testLoadValidFileSucceeds(): void
    {
        $file = $this->tempFile('.xml', '<root><child>value</child></root>');
        $doc = new XmlConfigDomDocument();
        $this->assertTrue($doc->load($file));
        $this->assertSame('root', $this->documentElement($doc)->localName);
    }

    public function testLoadMalformedFileThrowsDomException(): void
    {
        $file = $this->tempFile('.xml', '<root><unclosed></root>');
        $doc = new XmlConfigDomDocument();
        $this->expectException(\DOMException::class);
        $doc->load($file);
    }

    public function testLoadXmlValidStringSucceeds(): void
    {
        $doc = new XmlConfigDomDocument();
        $this->assertTrue($doc->loadXml('<root><child>value</child></root>'));
        $this->assertSame('root', $this->documentElement($doc)->localName);
    }

    public function testLoadXmlMalformedStringThrowsDomException(): void
    {
        $doc = new XmlConfigDomDocument();
        $this->expectException(\DOMException::class);
        $doc->loadXml('<root><unclosed></root>');
    }

    public function testXincludeResolvesIncludedContent(): void
    {
        $included = $this->tempFile('.xml', '<included>hello</included>');
        $main = $this->tempFile('.xml', sprintf(
            '<root xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="%s" parse="xml"/></root>',
            $included
        ));

        $doc = new XmlConfigDomDocument();
        $doc->load($main);
        $result = $doc->xinclude();

        $this->assertSame(1, $result);
        $firstChild = $this->documentElement($doc)->firstChild;
        if ($firstChild === null) {
            throw new \RuntimeException('Expected a first child node.');
        }
        $this->assertSame('included', $firstChild->localName);
    }

    public function testXincludeWithMissingTargetThrowsDomException(): void
    {
        $missing = sys_get_temp_dir() . '/xcdd-does-not-exist-' . bin2hex(random_bytes(8)) . '.xml';
        $main = $this->tempFile('.xml', sprintf(
            '<root xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="%s" parse="xml"/></root>',
            $missing
        ));

        $doc = new XmlConfigDomDocument();
        $doc->load($main);
        $this->expectException(\DOMException::class);
        $doc->xinclude();
    }

    public function testImportNodeCopiesNodeFromAnotherDocument(): void
    {
        $source = new XmlConfigDomDocument();
        $source->loadXml('<root><child>value</child></root>');

        $target = new XmlConfigDomDocument();
        $target->loadXml('<other/>');

        $childToImport = $this->documentElement($source)->firstChild;
        if ($childToImport === null) {
            throw new \RuntimeException('Expected a first child node.');
        }
        $imported = $target->importNode($childToImport, true);
        if (!$imported instanceof DOMNode) {
            throw new \RuntimeException('Expected importNode() to return a DOMNode.');
        }

        $this->assertSame('child', $imported->localName);
        $this->assertSame('value', $imported->textContent);
    }

    public function testSchemaValidateAgainstValidDocumentSucceeds(): void
    {
        $xsd = $this->tempFile('.xsd', <<<'XSD'
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="root" type="xs:string"/>
</xs:schema>
XSD);
        $doc = new XmlConfigDomDocument();
        $doc->loadXml('<root>hello</root>');

        $this->assertTrue($doc->schemaValidate($xsd));
    }

    public function testSchemaValidateAgainstInvalidDocumentThrows(): void
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
        $doc = new XmlConfigDomDocument();
        $doc->loadXml('<root><wrong-child>hello</wrong-child></root>');

        $this->expectException(\DOMException::class);
        $doc->schemaValidate($xsd);
    }

    public function testSchemaValidateSourceAgainstValidDocumentSucceeds(): void
    {
        $xsdSource = <<<'XSD'
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="root" type="xs:string"/>
</xs:schema>
XSD;
        $doc = new XmlConfigDomDocument();
        $doc->loadXml('<root>hello</root>');

        $this->assertTrue($doc->schemaValidateSource($xsdSource));
    }

    public function testSchemaValidateSourceAgainstInvalidDocumentThrows(): void
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
        $doc = new XmlConfigDomDocument();
        $doc->loadXml('<root><wrong-child>hello</wrong-child></root>');

        $this->expectException(\DOMException::class);
        $doc->schemaValidateSource($xsdSource);
    }

    public function testRelaxNgValidateAgainstValidDocumentSucceeds(): void
    {
        $rng = $this->tempFile('.rng', <<<'RNG'
<?xml version="1.0"?>
<element name="root" xmlns="http://relaxng.org/ns/structure/1.0">
  <text/>
</element>
RNG);
        $doc = new XmlConfigDomDocument();
        $doc->loadXml('<root>hello</root>');

        $this->assertTrue($doc->relaxNGValidate($rng));
    }

    public function testRelaxNgValidateAgainstInvalidDocumentThrows(): void
    {
        $rng = $this->tempFile('.rng', <<<'RNG'
<?xml version="1.0"?>
<element name="root" xmlns="http://relaxng.org/ns/structure/1.0">
  <element name="required-child">
    <text/>
  </element>
</element>
RNG);
        $doc = new XmlConfigDomDocument();
        $doc->loadXml('<root><wrong-child>hello</wrong-child></root>');

        $this->expectException(\DOMException::class);
        $doc->relaxNGValidate($rng);
    }

    public function testGetSandboxReturnsNullForNonQuioteDocument(): void
    {
        $doc = new XmlConfigDomDocument();
        $doc->loadXml('<root/>');

        $this->assertNull($doc->getSandbox());
    }

    public function testGetQuioteEnvelopeNamespaceReturnsNullForNonQuioteDocument(): void
    {
        $doc = new XmlConfigDomDocument();
        $doc->loadXml('<root/>');

        $this->assertNull($doc->getQuioteEnvelopeNamespace());
    }

    public function testGetSandboxAndEnvelopeNamespaceForQuioteDocument(): void
    {
        $doc = new XmlConfigDomDocument();
        $doc->loadXml(
            '<configurations xmlns="http://quiote.dev/quiote/config/global/envelope/1.1">'
            . '<sandbox/>'
            . '</configurations>'
        );

        $this->assertSame('http://quiote.dev/quiote/config/global/envelope/1.1', $doc->getQuioteEnvelopeNamespace());
        $sandbox = $doc->getSandbox();
        $this->assertNotNull($sandbox);
        $this->assertSame('sandbox', $sandbox->localName);
    }
}
