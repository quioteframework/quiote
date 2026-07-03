<?php

declare(strict_types=1);

namespace Quiote\Http;

use Symfony\Component\Mime\MimeTypes;

/**
 * Maps between Quiote format names, MIME types, and file extensions using symfony/mime.
 * A "format" is the canonical name used throughout the framework (e.g. 'json', 'html', 'xml').
 * It corresponds to the primary file extension by convention.
 * symfony/mime provides the underlying MIME type database; this class handles:
 *   - extension canonicalisation (e.g. 'htm' → 'html', 'jpeg' → 'jpg')
 *   - charset determination from MIME type structure
 *   - a curated list of formats recognised for content negotiation
 */
final class MimeTypeRegistry
{
    /**
     * Canonical format names (= primary file extensions) we recognise.
     * symfony/mime handles all MIME type lookups; this list scopes allMimeTypes().
     * @var string[]
     */
    private static array $supportedFormats = [
        'html', 'txt', 'css', 'json', 'js',
        'rdf', 'rss', 'atom', 'xml', 'kml',
        'bmp', 'gif', 'png', 'jpg', 'svg', 'psd', 'eps', 'ico',
        'mov', 'mp3', 'mp4', 'ogg', 'ogv', 'webm', 'webp',
        'eot', 'otf', 'ttf', 'woff', 'woff2',
        'pdf', 'zip', 'rar', 'exe', 'msi', 'cab',
        'doc', 'docx', 'rtf', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
        'csv',
    ];

    /**
     * Non-canonical extensions → canonical format name.
     * Used by both formatForExtension() and formatsForMime().
     * @var array<string, string>
     */
    private static array $extensionAliases = [
        // HTML variants
        'htm'   => 'html',
        'php'   => 'html',
        'xhtml' => 'html',
        'xht'   => 'html',
        // JPEG variants
        'jpeg'  => 'jpg',
        'jpe'   => 'jpg',
        'jfif'  => 'jpg',
        // Compressed SVG
        'svgz'  => 'svg',
        // PostScript / Illustrator
        'ai'    => 'eps',
        'ps'    => 'eps',
        // QuickTime
        'qt'    => 'mov',
        // JS module variants
        'mjs'   => 'js',
        'cjs'   => 'js',
        'jsm'   => 'js',
    ];

    private static function sf(): MimeTypes
    {
        return MimeTypes::getDefault();
    }

    private static function wantsCharset(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/')
            || str_ends_with($mimeType, '+xml')
            || str_ends_with($mimeType, '+json')
            || in_array($mimeType, ['application/json', 'application/javascript', 'application/xml'], true);
    }

    /**
     * Returns the primary MIME type for a format name, with '; charset=UTF-8' appended
     * for text-based types, or null if the format is unknown.
     * Example: primaryMimeType('json') === 'application/json; charset=UTF-8'
     *          primaryMimeType('png')  === 'image/png'
     */
    public static function primaryMimeType(string $format): ?string
    {
        $mimes = self::sf()->getMimeTypes($format);
        $mime = $mimes[0] ?? null;
        if ($mime === null) {
            return null;
        }
        return self::wantsCharset($mime) ? $mime . '; charset=UTF-8' : $mime;
    }

    /**
     * Returns an ordered list of format names for a MIME type, most-canonical first.
     * Multiple formats are returned when the MIME type maps to several recognised extensions,
     * letting callers try execute methods in order (e.g. executeJs(), executeMjs()).
     * Example: formatsForMime('application/json')      === ['json']
     *          formatsForMime('application/xhtml+xml') === ['html']
     *          formatsForMime('image/jpeg')             === ['jpg']
     * @return string[]
     */
    public static function formatsForMime(string $mime): array
    {
        $formats = [];
        foreach (self::sf()->getExtensions($mime) as $ext) {
            $format = self::$extensionAliases[$ext] ?? $ext;
            if (in_array($format, self::$supportedFormats, true) && !in_array($format, $formats, true)) {
                $formats[] = $format;
            }
        }
        return $formats;
    }

    /**
     * Returns the primary format name for a MIME type, or null if unrecognised.
     * Example: formatForMime('application/json') === 'json'
     *          formatForMime('image/png')         === 'png'
     */
    public static function formatForMime(string $mime): ?string
    {
        return self::formatsForMime($mime)[0] ?? null;
    }

    /**
     * Returns the canonical format name for a file extension, or null if unrecognised.
     * Example: formatForExtension('htm')  === 'html'
     *          formatForExtension('jpeg') === 'jpg'
     */
    public static function formatForExtension(string $extension): ?string
    {
        $ext = strtolower($extension);
        $format = self::$extensionAliases[$ext] ?? $ext;
        return in_array($format, self::$supportedFormats, true) ? $format : null;
    }

    /**
     * Returns all MIME type strings we recognise, for use in content negotiation.
     * @return string[]
     */
    /**
     * Flattened list of every supported MIME type, computed once per worker.
     * The source ($supportedFormats + symfony/mime's static tables) is constant
     * for the process lifetime, so there is no reason to rebuild it on every
     * content-negotiated request.
     * @var string[]|null
     */
    private static ?array $allMimeTypesMemo = null;

    public static function allMimeTypes(): array
    {
        if (self::$allMimeTypesMemo !== null) {
            return self::$allMimeTypesMemo;
        }
        $all = [];
        $sf = self::sf();
        foreach (self::$supportedFormats as $format) {
            foreach ($sf->getMimeTypes($format) as $mime) {
                $all[] = $mime;
            }
        }
        return self::$allMimeTypesMemo = array_values(array_unique($all));
    }

    /**
     * Formats a dynamic action response can realistically be negotiated into,
     * most-preferred first. Content negotiation picks an action *output type*
     * (executeHtml/executeJson/executePdf/...), so negotiating against the full
     * asset MIME universe (fonts, video, images, executables) is both wasteful
     * and wrong — an action never negotiates its response into font/woff2. HTML
     * is first so it wins wildcard/tie requests (the common browser case).
     * @var string[]
     */
    private static array $negotiableFormats = ['html', 'json', 'xml', 'pdf', 'csv', 'xlsx', 'docx', 'txt'];

    /** @var string[]|null */
    private static ?array $negotiableMimeTypesMemo = null;

    /**
     * The MIME types for {@see $negotiableFormats}, html-first, memoized. This
     * is the priority list content negotiation should score an Accept header
     * against — a handful of entries instead of ~60, so getBest()'s inner loop
     * does far less work per request.
     * @return string[]
     */
    public static function negotiableMimeTypes(): array
    {
        if (self::$negotiableMimeTypesMemo !== null) {
            return self::$negotiableMimeTypesMemo;
        }
        $mimes = [];
        $sf = self::sf();
        foreach (self::$negotiableFormats as $format) {
            foreach ($sf->getMimeTypes($format) as $mime) {
                $mimes[] = $mime;
            }
        }
        return self::$negotiableMimeTypesMemo = array_values(array_unique($mimes));
    }
}
