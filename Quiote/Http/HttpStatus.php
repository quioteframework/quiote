<?php

namespace Quiote\Http;

/**
 * The single source of truth for HTTP status-code validity and reason phrases.
 *
 * Validity is a range check, not membership of a code list: the IANA registry grows, and a
 * framework that has to be edited before an application can emit a new status code blocks
 * that application for no benefit. Status validity is also independent of the protocol
 * version carrying it.
 *
 * @since      3.2.0
 */
final class HttpStatus
{
    /** Lowest status code any HTTP version permits. */
    public const int MIN = 100;

    /** Highest status code the three-digit wire format permits. */
    public const int MAX = 599;

    /**
     * Reason phrases for the codes that carry one. A lookup convenience only: validity is
     * decided by {@see isValid()}, never by presence in this map, so a valid code that is
     * absent here is still emittable and simply gets a generic class-derived phrase.
     * @var        array<int, string>
     */
    private const array PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => "I'm a teapot",
        421 => 'Misdirected Request',
        422 => 'Unprocessable Content',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    private function __construct() {}

    /**
     * Whether $code can be sent as an HTTP status.
     *
     * Accepts a numeric string as well as an int, because the response API carries
     * `string|int` status codes and config-sourced codes arrive as strings. A non-numeric
     * string, or anything outside 100-599, is rejected.
     */
    public static function isValid(string|int $code): bool
    {
        if (is_string($code)) {
            if ($code === '' || !ctype_digit($code)) {
                return false;
            }
            $code = (int) $code;
        }

        return $code >= self::MIN && $code <= self::MAX;
    }

    /**
     * The reason phrase for $code, or a generic class-derived phrase for a valid code with
     * no registered phrase. An invalid code yields the empty string, which PSR-7 permits
     * as a reason phrase, keeping this total rather than throwing.
     */
    public static function phrase(string|int $code): string
    {
        if (!self::isValid($code)) {
            return '';
        }
        $code = (int) $code;

        return self::PHRASES[$code] ?? match (intdiv($code, 100)) {
            1 => 'Informational',
            2 => 'Success',
            3 => 'Redirection',
            4 => 'Client Error',
            5 => 'Server Error',
            default => '',
        };
    }

    /**
     * Whether $code is a redirect status that carries a Location header.
     * 304 is deliberately excluded: it is a 3xx but not a redirect.
     */
    public static function isRedirect(string|int $code): bool
    {
        if (!self::isValid($code)) {
            return false;
        }
        $code = (int) $code;

        return $code !== 304 && intdiv($code, 100) === 3;
    }
}
