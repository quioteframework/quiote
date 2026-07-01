<?php
namespace Quiote\Execution;

use Quiote\Context;
use Quiote\Response\WebResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * ViewInitContext: minimal, presentation-focused initialization contract for views.
 * Decouples views from action/request execution mechanics and legacy container.
 */
interface ViewInitContext
{
    public function getContext(): Context;
    public function getViewModuleName(): string; // canonical module hosting the view
    public function getViewName(): string;       // canonical view name
    public function getOutputTypeName(): string; // output type name (lowercase)
    public function getActionModuleName(): ?string; // originating action module (for slots/forwards)
    public function getActionName(): ?string;       // originating action name
    public function getActionAttributes(): array;   // snapshot of action attributes (read-only for templates)
    public function getResponse(): WebResponse;   // canonical web response
    /**
     * Optional PSR-7 response adapter backing the legacy response.
     * Views may use this when interacting with PSR-aware middleware or code.
     * @return ResponseInterface|null
     */
    public function getPsrResponse(): ?ResponseInterface;
}
