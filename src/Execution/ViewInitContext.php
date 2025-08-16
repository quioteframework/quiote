<?php
namespace Agavi\Execution;

use Agavi\AgaviContext;
use Agavi\Response\AgaviResponse;

/**
 * ViewInitContext: minimal, presentation-focused initialization contract for views.
 * Decouples views from action/request execution mechanics and legacy container.
 */
interface ViewInitContext
{
    public function getContext(): AgaviContext;
    public function getViewModuleName(): string; // canonical module hosting the view
    public function getViewName(): string;       // canonical view name
    public function getOutputTypeName(): string; // output type name (lowercase)
    public function getActionModuleName(): ?string; // originating action module (for slots/forwards)
    public function getActionName(): ?string;       // originating action name
    public function getActionAttributes(): array;   // snapshot of action attributes (read-only for templates)
    public function getResponse(): AgaviResponse;   // legacy response handle (will be abstracted later)
}
