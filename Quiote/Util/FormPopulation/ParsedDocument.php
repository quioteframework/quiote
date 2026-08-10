<?php

declare(strict_types=1);

namespace Quiote\Util\FormPopulation;

/**
 * A response body parsed into a DOM, with the decisions the parse made.
 *
 * Those decisions -- whether the document is XHTML, whether it was parsed as
 * XML, whether it already carried an XML prolog, and what namespace prefix
 * XPath expressions need -- are all made while reading the document and all
 * needed again when writing it back out. Carrying them together is what keeps
 * the serializer from having to guess.
 */
final readonly class ParsedDocument
{
    public function __construct(
        public \DOMDocument $document,
        public \DOMXPath $xpath,
        /** Prefix XPath expressions need for HTML elements, "html:" or "". */
        public string $xmlnsPrefix,
        public bool $isXhtml,
        public bool $parsedAsXml,
        public bool $hadXmlProlog,
    ) {}
}
