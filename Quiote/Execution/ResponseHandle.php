<?php
namespace Quiote\Execution;

use Quiote\Response\WebResponse;

/**
 * Minimal façade exposing response operations in no-container execution paths.
 * Designed to work with WebResponse or legacy Response-compatible objects.
 */
class ResponseHandle
{
    public function __construct(private readonly mixed $inner) {}
    public function getInner(): mixed { return $this->inner; }
    public function append(string $content): void { if(method_exists($this->inner,'appendContent')) { $this->inner->appendContent($content); } }
    public function set(string $content): void { if(method_exists($this->inner,'setContent')) { $this->inner->setContent($content); } }
    public function getContent(): string { return (string) (method_exists($this->inner,'getContent') ? $this->inner->getContent() : ''); }
    public function clear(): void { if(method_exists($this->inner,'clearContent')) { $this->inner->clearContent(); } }
    public function setStatusCode(int $code): void { if(method_exists($this->inner,'setHttpStatusCode')) { $this->inner->setHttpStatusCode($code); } }
    public function addHeader(string $name, string $value, bool $replace = true): void { if(method_exists($this->inner,'setHttpHeader')) { $this->inner->setHttpHeader($name,$value,$replace); } }
}
