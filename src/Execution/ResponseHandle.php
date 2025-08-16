<?php
namespace Agavi\Execution;

use Agavi\Response\AgaviResponse;

/**
 * Minimal façade exposing response operations in no-container execution paths.
 */
class ResponseHandle
{
    public function __construct(private AgaviResponse $inner) {}
    public function getInner(): AgaviResponse { return $this->inner; }
    public function append(string $content): void { $this->inner->appendContent($content); }
    public function set(string $content): void { $this->inner->setContent($content); }
    public function getContent(): string { return (string)$this->inner->getContent(); }
    public function clear(): void { if(method_exists($this->inner,'clearContent')) { $this->inner->clearContent(); } }
    public function setStatusCode(int $code): void { if(method_exists($this->inner,'setHttpStatusCode')) { $this->inner->setHttpStatusCode($code); } }
    public function addHeader(string $name, string $value, bool $replace = true): void { if(method_exists($this->inner,'setHttpHeader')) { $this->inner->setHttpHeader($name,$value,$replace); } }
}
