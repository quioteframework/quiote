<?php

namespace Quiote\Response;

use Psr\Http\Message\ResponseInterface;
use Quiote\Controller\OutputType;

/**
 * The response an action or view writes to: body, status, headers, cookies, redirect and
 * content type, plus the conversion to PSR-7 the runtime emits.
 *
 * Narrower than {@see WebResponse}: its serialization hooks, parameter holder and worker-reset
 * behaviour serve the framework's own plumbing, not the code composing a response.
 *
 * Named for the framework's response rather than PSR-7's, which {@see WebResponse} is not --
 * it is mutable by design, being the thing an action progressively fills in. Convert with
 * {@see toPsrResponse()} at the point a real PSR-7 message is wanted.
 *
 * @since      3.2.0
 */
interface WebResponseInterface
{
    /**
     * The response body: a string, a scalar, a stream resource, or null when unset.
     * @return     mixed
     */
    public function getContent();

    /**
     * Replace the body.
     * @param      mixed $content
     * @return     mixed
     */
    public function setContent($content);

    /**
     * Append to a string body.
     * @param      mixed $content
     * @return     mixed
     */
    public function appendContent($content);

    /**
     * Prepend to a string body.
     * @param      mixed $content
     * @return     mixed
     */
    public function prependContent($content);

    /**
     * Whether a body has been set.
     * @return     bool
     */
    public function hasContent();

    /**
     * Discard the body.
     * @return     mixed
     */
    public function clearContent();

    /**
     * The status code, as a numeric string.
     * @return     string
     */
    public function getHttpStatusCode();

    /**
     * Set the status code.
     * @return     mixed
     * @throws     \Quiote\Exception\QuioteException For a code outside the acceptable range.
     */
    public function setHttpStatusCode(string|int $code);

    /**
     * All values of one header, or null when unset.
     * @param      string $name
     * @return     ?list<string>
     */
    public function getHttpHeader($name);

    /**
     * Every header set on this response.
     * @return     array<string, list<string>>
     */
    public function getHttpHeaders();

    /**
     * Whether a header is set.
     * @param      string $name
     * @return     bool
     */
    public function hasHttpHeader($name);

    /**
     * Set a header, replacing any existing values unless $replace is false.
     * @param      string $name
     * @param      mixed $value
     * @param      bool $replace
     * @return     mixed
     */
    public function setHttpHeader($name, $value, $replace = true);

    /**
     * Add a header value, keeping any already set.
     * @param      string $name
     * @param      mixed $value
     * @return     mixed
     */
    public function addHttpHeader($name, $value);

    /**
     * Remove a header.
     * @param      string $name
     * @return     mixed
     */
    public function removeHttpHeader($name);

    /**
     * The response's Content-Type.
     * @return     ?string
     */
    public function getContentType();

    /**
     * Set the response's Content-Type.
     * @param      string $type
     * @return     mixed
     */
    public function setContentType($type);

    /**
     * The output type this response is being rendered in.
     * @return     ?OutputType
     */
    public function getOutputType();

    /**
     * Set the output type this response is being rendered in.
     * @return     mixed
     */
    public function setOutputType(OutputType $outputType);

    /**
     * Queue a cookie. Every attribute defaults to this response's configured value when null.
     * @param      string $name
     * @param      mixed $value Null, false or the empty string deletes the cookie.
     * @param      int|string|null $lifetime Seconds, or a strtotime()-parseable string.
     * @param      ?string $path
     * @param      ?string $domain
     * @param      ?bool $secure
     * @param      ?bool $httponly
     * @param      callable|false|null $encodeCallback False asserts $value is pre-encoded.
     * @param      ?string $samesite
     * @return     mixed
     */
    public function setCookie($name, $value, $lifetime = null, $path = null, $domain = null, $secure = null, $httponly = null, $encodeCallback = null, $samesite = null);

    /**
     * Queue a cookie deletion. The attributes must match the cookie that was set.
     * @param      string $name
     * @param      ?string $path
     * @param      ?string $domain
     * @param      ?bool $secure
     * @param      ?bool $httponly
     * @return     mixed
     */
    public function unsetCookie($name, $path = null, $domain = null, $secure = null, $httponly = null);

    /**
     * Every queued cookie definition, keyed by name.
     * @return     array<string, mixed>
     */
    public function getCookies();

    /**
     * Redirect to $location with $code.
     * @param      mixed $location
     * @param      int|string $code
     * @return     mixed
     * @throws     \Quiote\Exception\QuioteException For a code outside the acceptable range.
     */
    public function setRedirect($location, $code = 302);

    /**
     * The queued redirect, or null when none is set.
     * @return     ?array{location: string, code: int|string}
     */
    public function getRedirect();

    /**
     * Whether a redirect is queued.
     * @return     bool
     */
    public function hasRedirect();

    /**
     * Discard any queued redirect.
     * @return     mixed
     */
    public function clearRedirect();

    /**
     * Materialize this response as PSR-7, with no side effects on any output channel.
     *
     * @param      ?OutputType $outputType Output type whose headers to fold in; defaults to
     *             this response's own.
     */
    public function toPsrResponse(?OutputType $outputType = null): ResponseInterface;
}
