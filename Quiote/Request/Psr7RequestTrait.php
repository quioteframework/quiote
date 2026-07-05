<?php

declare(strict_types=1);

namespace Quiote\Request;

use Psr\Http\Message\ServerRequestInterface;

trait Psr7RequestTrait
{
    /**
     * Helper used by WebRequest for reading intrinsic HTTP request data.
     * NOTE: This intentionally DOES NOT look at internal "runtime" parameters or
     * PSR-7 attributes. Runtime parameters are managed separately inside
     * WebRequest (setParameter / appendParameter) to preserve the historic
     * separation between request parameters (user supplied) and attributes
     * (framework / execution context data). Views are given access to parameters
     * only through the WebRequest API, not raw attributes.
     * Resolution order here (lowest precedence first – higher layers override
     * in WebRequest::getParameter before delegating to this helper):
     *   1. Parsed body (POST form or JSON decoded array)
     *   2. Query params (GET)
     *   3. Cookies
     *   4. Headers
     *   5. Uploaded files
     * @param mixed $default
     * @return mixed
     */
    protected function getRequestParam(ServerRequestInterface $request, string $name, $default = null)
    {

        // parsed body
        $body = $request->getParsedBody();
        if (is_array($body) && array_key_exists($name, $body)) {
            return $body[$name];
        }

        // query params
        $query = $request->getQueryParams();
        if (array_key_exists($name, $query)) {
            return $query[$name];
        }

        $cookies = $request->getCookieParams();
        if (array_key_exists($name, $cookies)) {
            return $cookies[$name];
        }

        $headers = $request->getHeaders();
        if (array_key_exists($name, $headers)) {
            return $headers[$name];
        }

        $files = $request->getUploadedFiles();
        if (array_key_exists($name, $files)) {
            return $files[$name];
        }

        if ($request->getAttribute('throw_on_missing_access', false) === true) {
            // In migration phase we just return default; hook for future exception
        }

        return $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function getRequestParams(ServerRequestInterface $request, ?string $source = null)
    {
        $merge = (static fn(?array $a, ?array $b): array => array_merge($a ?? [], $b ?? []));

        if ($source === null) {
            $all = [];
            $all = $merge($all, $request->getQueryParams());
            $parsed = $request->getParsedBody();
            $all = $merge($all, is_array($parsed) ? $parsed : []);
            $all = $merge($all, $request->getCookieParams());
            $all = $merge($all, $request->getHeaders());
            $all = $merge($all, $request->getUploadedFiles());
            return $all;
        }

        if ($source === "parameters") {
            $parsed = $request->getParsedBody();
            return $merge($request->getQueryParams(), is_array($parsed) ? $parsed : []);
        }

        if ($source === "cookies") {
            return $request->getCookieParams();
        }

        if ($source === "files") {
            return $request->getUploadedFiles();
        }
        
        if ($source === "headers") {
            return $request->getHeaders();
        }

        if ($source === "attributes") {
            return $request->getAttributes();
        }

        return [];
    }

    /**
     * @return ?ServerRequestInterface
     */
    protected function withoutParameter(ServerRequestInterface $request, string $name, ?string $source = null) {

        // parameters (query + parsed body)
        if ($source === "parameters" || $source === null) {
            $body = $request->getParsedBody();
            if (is_array($body) && array_key_exists($name, $body)) {
                unset($body[$name]);
                return $request->withParsedBody($body);
            }
            $query = $request->getQueryParams();
            if (array_key_exists($name, $query)) {
                unset($query[$name]);
                return $request->withQueryParams($query);
            }
        }

        // cookies
        if ($source === "cookies" || $source === null) {
            $cookies = $request->getCookieParams();
            if (array_key_exists($name, $cookies)) {
                unset($cookies[$name]);
                return $request->withCookieParams($cookies);
            }
        }

        // headers
        if ($source === "headers" || $source === null) {
            if ($request->hasHeader($name)) {
                return $request->withoutHeader($name);
            }
        }

        // files
        if ($source === "files" || $source === null) {
            $files = $request->getUploadedFiles();
            if (array_key_exists($name, $files)) {
                unset($files[$name]);
                return $request->withUploadedFiles($files);
            }
        }

        return null;
    }
}
