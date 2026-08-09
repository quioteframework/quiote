<?php
namespace Quiote\Execution;

use Quiote\Response\WebResponse;

/**
 * Minimal façade exposing response operations in no-container execution paths.
 * Designed to work with WebResponse or legacy Response-compatible objects.
 */
class ResponseHandle
{
    public function __construct(private readonly object $inner) {}
    /** Returns the wrapped response object, for callers needing an API this façade does not expose. */
    public function getInner(): mixed { return $this->inner; }
    /** Appends content to the wrapped response; does nothing when it exposes no appendContent(). */
    public function append(string $content): void { if(method_exists($this->inner,'appendContent')) { $this->inner->appendContent($content); } }
    /** Replaces the wrapped response's content; does nothing when it exposes no setContent(). */
    public function set(string $content): void { if(method_exists($this->inner,'setContent')) { $this->inner->setContent($content); } }
    /** Returns the wrapped response's content, or an empty string when it exposes no getContent(). */
    public function getContent(): string { return (string) (method_exists($this->inner,'getContent') ? $this->inner->getContent() : ''); }
    /** Discards the wrapped response's content; does nothing when it exposes no clearContent(). */
    public function clear(): void { if(method_exists($this->inner,'clearContent')) { $this->inner->clearContent(); } }
    /** Sets the wrapped response's HTTP status code; does nothing when it exposes no setHttpStatusCode(). */
    public function setStatusCode(int $code): void { if(method_exists($this->inner,'setHttpStatusCode')) { $this->inner->setHttpStatusCode($code); } }
    /**
     * Sets an HTTP header on the wrapped response, replacing any existing value unless $replace is false.
     *
     * Does nothing when the wrapped object exposes no setHttpHeader().
     */
    public function addHeader(string $name, string $value, bool $replace = true): void { if(method_exists($this->inner,'setHttpHeader')) { $this->inner->setHttpHeader($name,$value,$replace); } }
}
