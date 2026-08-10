<?php

declare(strict_types=1);

namespace Quiote\Util\FormPopulation;

use Quiote\Exception\QuioteException;

/**
 * Turns the populated DOM back into the response body.
 *
 * HTML serializes with saveHTML() and needs nothing else. XHTML is where the
 * work is: DOM's XML serializer produces output that browsers and validators
 * object to in three separate ways, and each fix here is for one of them.
 *
 * The fixes are only correct for a document that was parsed as HTML but is
 * being written as XML, which is why each is conditional rather than
 * unconditional. A document parsed as XML in the first place already has the
 * namespaces and CDATA sections it needs.
 */
final readonly class DocumentSerializer
{
    public function __construct(
        private \DOMDocument $document,
        private bool $parsedAsXml,
        private bool $properXhtml,
        private bool $hadXmlProlog,
        private bool $isUtf8,
    ) {}

    /**
     * @param array<string, mixed> $cfg
     * @throws QuioteException if a final regular-expression pass fails.
     */
    public function serialize(bool $xhtml, array $cfg): string
    {
        if (!$xhtml) {
            return (string) $this->document->saveHTML();
        }

        if (!$this->parsedAsXml) {
            $this->repairDocumentElementNamespaces();
        }

        $out = (string) $this->document->saveXML(null, self::asInt($cfg['savexml_options'] ?? 0));

        $firstError = null;

        if ((!$this->parsedAsXml || !$this->properXhtml) && ($cfg['cdata_fix'] ?? false)) {
            $out = $this->applyCdataFix($out, $firstError);
        }

        $out = $this->removeGeneratedProlog($out, $cfg, $firstError);

        if ($firstError !== null && $firstError !== 0) {
            throw new QuioteException($this->describePregFailure($firstError));
        }

        return $out;
    }

    /**
     * Works around DOM emitting two xmlns attributes on <html> for a document
     * that was parsed as HTML. The attributes have to be removed and recreated,
     * and a namespaced one recreated in its namespace -- in HTML parsing mode
     * the attribute node carries no namespace of its own even when its name has
     * a prefix.
     */
    private function repairDocumentElementNamespaces(): void
    {
        $documentElement = $this->document->documentElement;
        if ($documentElement === null) {
            return;
        }

        $remove = [];
        $reset = [];

        // Never mutate the DOMNodeList being walked.
        foreach ($documentElement->attributes as $attribute) {
            $remove[] = $attribute;

            if ($attribute->nodeName === 'xmlns') {
                continue;
            }

            $nameParts = explode(':', $attribute->nodeName);
            $copy = $attribute;

            if (isset($nameParts[1])) {
                $namespaceUri = $attribute->parentNode?->lookupNamespaceURI($nameParts[0]);
                if ($namespaceUri) {
                    // Known internally even in HTML mode -- typically xml: or xmlns:.
                    $copy = $this->document->createAttributeNS($namespaceUri, $attribute->nodeName);
                }
            }

            $copy->nodeValue = $attribute->nodeValue;
            $reset[] = $copy;
        }

        foreach ($remove as $attribute) {
            $documentElement->removeAttributeNode($attribute);
        }
        foreach ($reset as $attribute) {
            $documentElement->setAttributeNode($attribute);
        }
    }

    /**
     * Wraps CDATA sections in the comment dance that keeps inline style and
     * script blocks working in browsers parsing the document as HTML.
     *
     * @see https://www.hixie.ch/advocacy/xhtml
     */
    private function applyCdataFix(string $out, ?int &$firstError): string
    {
        $u = $this->isUtf8 ? 'u' : '';

        // The closing-tag patterns do not lead with \s*: a leading greedy-whitespace
        // expression makes these passes pathologically slow on a large document.
        $passes = [
            ['/<style([^>]*)>\s*<!\[CDATA\[\s*?/iU' . $u, '<style$1><!--/*--><![CDATA[/*><!--*/' . "\n"],
            ['/\]\]>\s*<\/style>/iU' . $u, "\n" . '/*]]>*/--></style>'],
            ['/<script([^>]*)>\s*<!\[CDATA\[\s*?/iU' . $u, '<script$1><!--//--><![CDATA[//><!--' . "\n"],
            ['/\]\]>\s*<\/script>/iU' . $u, "\n" . '//--><!]]></script>'],
        ];

        foreach ($passes as [$pattern, $replacement]) {
            $out = (string) preg_replace($pattern, $replacement, $out);
            $firstError ??= preg_last_error() ?: null;
        }

        return $out;
    }

    /**
     * Drops the XML prolog DOM adds on its own.
     *
     * A document that had no prolog to begin with should not gain one. When it
     * was parsed as HTML, DOM puts a second prolog *after* the DOCTYPE, ending
     * in two question marks rather than one.
     *
     * @param array<string, mixed> $cfg
     */
    private function removeGeneratedProlog(string $out, array $cfg, ?int &$firstError): string
    {
        $u = $this->isUtf8 ? 'u' : '';

        if (($cfg['remove_auto_xml_prolog'] ?? false) && !$this->hadXmlProlog) {
            $out = (string) preg_replace('/<\?xml.*?\?>\s+/iU' . $u, '', $out);
            $firstError ??= preg_last_error() ?: null;
        } elseif (!$this->parsedAsXml) {
            $out = (string) preg_replace('/<\?xml.*?\?\?>\s+/iU' . $u, '', $out);
            $firstError ??= preg_last_error() ?: null;
        }

        return $out;
    }

    private function describePregFailure(int $error): string
    {
        $reason = match ($error) {
            PREG_BAD_UTF8_ERROR => 'the input contained malformed UTF-8 data.',
            PREG_RECURSION_LIMIT_ERROR => 'the recursion limit (defined by "pcre.recursion_limit") was hit. This shouldn\'t happen unless you changed that limit yourself in php.ini or using ini_set(). If the problem is not on your end, please file a bug report with a reproduce case on the Quiote issue tracker.',
            PREG_BACKTRACK_LIMIT_ERROR => 'the backtrack limit (defined by "pcre.backtrack_limit") was hit. This shouldn\'t happen unless you changed that limit yourself in php.ini or using ini_set(). If the problem is not on your end, please file a bug report with a reproduce case on the Quiote issue tracker.',
            default => 'an internal PCRE error occurred. As a quick countermeasure, try to upgrade PHP (and the bundled PCRE) as well as libxml to the latest versions to see if the problem goes away. If the issue persists, file a bug report with a reproduce case on the Quiote issue tracker.',
        };

        return "Form Population Filter encountered an error while performing final regular expression replaces on the output.\n"
            . 'The error reported by preg_last_error() indicates that ' . $reason;
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
