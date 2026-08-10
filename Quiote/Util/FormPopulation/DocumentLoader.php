<?php

declare(strict_types=1);

namespace Quiote\Util\FormPopulation;

use Quiote\Config\Config;
use Quiote\Exception\ParseException;
use Quiote\Logging\CategoryLogger;

/**
 * Parses a response body into a DOM, deciding as it goes whether the document
 * is XHTML and how strictly to read it.
 *
 * Two things make this more than a loadHTML() call. First, the document is
 * whatever a view produced, so parse errors are normal and how loudly to
 * complain is configuration: below the configured threshold they are logged
 * and populating continues, above it they abort the request, and a fatal
 * always stops populating because there is no usable tree to work on.
 *
 * Second, an XHTML document served as ISO-8859-1 needs an XML prolog for the
 * parser to read it correctly, so one is added when the content type declares
 * a charset and the document has none. Whether it was added matters later --
 * a document that arrived without a prolog should not leave with one.
 */
final readonly class DocumentLoader
{
    public function __construct(private CategoryLogger $logger) {}

    /**
     * @param array<string, mixed> $cfg
     * @param ?string $contentTypeHeader The output type's Content-Type, for its charset.
     * @return ?ParsedDocument Null when a fatal error leaves nothing to populate.
     * @throws ParseException if an error exceeds the configured tolerance.
     */
    public function load(string $output, array $cfg, ?string $contentTypeHeader): ?ParsedDocument
    {
        $forceOutputMode = strtolower(self::asString($cfg['force_output_mode'] ?? ''));
        $isXhtml = (preg_match('/<!DOCTYPE[^>]+XHTML[^>]+/', $output) > 0 && $forceOutputMode !== 'html')
            || $forceOutputMode === 'xhtml';

        $hadXmlProlog = false;
        if ($isXhtml && preg_match('/^<\?xml[^\?]*\?>/', $output)) {
            $hadXmlProlog = true;
        } elseif ($isXhtml && $contentTypeHeader !== null) {
            // media-type = type "/" subtype *( ";" parameter ), per RFC 2616 3.7.
            $charsetPattern = '/;\s*charset=(")?(?P<charset>.+?(?(1)(?=(?<!\\\\)")|($|(?=[;\s]))))(?(1)")/i';
            if (preg_match($charsetPattern, $contentTypeHeader, $matches)) {
                // Declaring the encoding up front is what makes ISO-8859-1 and friends parse.
                $output = "<?xml version='1.0' encoding='" . self::asString($matches['charset']) . "' ?>\n" . $output;
            }
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new \DOMDocument();
        $document->substituteEntities = self::asBool($cfg['dom_substitute_entities'] ?? null, false);
        $document->resolveExternals = self::asBool($cfg['dom_resolve_externals'] ?? null, false);
        $document->validateOnParse = self::asBool($cfg['dom_validate_on_parse'] ?? null, false);
        $document->preserveWhiteSpace = self::asBool($cfg['dom_preserve_white_space'] ?? null, true);
        $document->formatOutput = self::asBool($cfg['dom_format_output'] ?? null, false);

        $parseAsXml = $isXhtml && (bool) ($cfg['parse_xhtml_as_xml'] ?? false);
        $xmlnsPrefix = '';

        if ($parseAsXml) {
            $document->loadXML($output);
            $xpath = new \DOMXPath($document);
            if ($document->documentElement && $document->documentElement->namespaceURI) {
                $xpath->registerNamespace('html', $document->documentElement->namespaceURI);
                $xmlnsPrefix = 'html:';
            }
        } else {
            $document->loadHTML($output);
            $xpath = new \DOMXPath($document);
        }

        $fatal = $this->reportParseErrors($cfg, $output, $previousUseInternalErrors);

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if ($fatal) {
            return null;
        }

        return new ParsedDocument($document, $xpath, $xmlnsPrefix, $isXhtml, $parseAsXml, $hadXmlProlog);
    }

    /**
     * @param array<string, mixed> $cfg
     * @return bool True when the document is too broken to populate.
     * @throws ParseException if the worst error exceeds the configured tolerance.
     */
    private function reportParseErrors(array $cfg, string $output, bool $previousUseInternalErrors): bool
    {
        if (libxml_get_last_error() === false) {
            return false;
        }

        $messages = [];
        $worst = LIBXML_ERR_NONE;
        foreach (libxml_get_errors() as $error) {
            $level = match ($error->level) {
                LIBXML_ERR_WARNING => 'Warning',
                LIBXML_ERR_ERROR => 'Error',
                default => 'Fatal',
            };
            $messages[] = sprintf('[%s #%d] Line %d: %s', $level, $error->code, $error->line, $error->message);
            $worst = max($worst, $error->level);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        $summary = sprintf(
            "Form Population Filter encountered the following error%s while parsing the document:\n\n"
            . "%s\n\n"
            . "Non-fatal errors are typically recoverable; you may set the 'ignore_parse_errors' configuration parameter to LIBXML_ERR_WARNING or LIBXML_ERR_ERROR (default) to suppress them.\n"
            . "If you set 'ignore_parse_errors' to LIBXML_ERR_FATAL (recommended for production), Form Population Filter will silently abort execution in the event of fatal errors.\n"
            . 'Regardless of the setting, all errors encountered will be logged.',
            count($messages) > 1 ? 's' : '',
            implode("\n", $messages)
        );

        $logThreshold = $cfg['log_parse_errors'] ?? false;
        if (Config::getBool('core.use_logging', false) && $logThreshold !== false && $worst >= $logThreshold) {
            $line = $summary . "\n\nResponse content:\n\n" . $output;
            match ($worst) {
                LIBXML_ERR_WARNING => $this->logger->warning($line),
                LIBXML_ERR_ERROR => $this->logger->error($line),
                LIBXML_ERR_FATAL => $this->logger->critical($line),
                default => $this->logger->info($line),
            };
        }

        if ($worst > ($cfg['ignore_parse_errors'] ?? LIBXML_ERR_ERROR)) {
            throw new ParseException($summary);
        }

        // A fatal leaves no usable tree, so populating aborts silently rather than
        // rewriting a document DOM could not read.
        return $worst === LIBXML_ERR_FATAL;
    }

    private static function asBool(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : (is_scalar($value) ? (bool) $value : $default);
    }

    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
