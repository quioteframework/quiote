<?php
namespace Quiote\Execution;

use Quiote\Context;
use Quiote\Response\WebResponse;
use Quiote\Validator\ValidationManager;
use Psr\Http\Message\ResponseInterface;

/**
 * ViewInitContext: minimal, presentation-focused initialization contract for views.
 * Decouples views from action/request execution mechanics and legacy container.
 */
interface ViewInitContext
{
    /** Returns the application Context the view is rendering under. */
    public function getContext(): Context;
    /** Returns the canonical name of the module hosting the view. */
    public function getViewModuleName(): string; // canonical module hosting the view
    /**
     * Returns the module name for legacy code written against the action-container
     * convention (`getModuleName()`), which predates the view/action module split.
     * Implementations fall back to the view module when there is no originating action.
     */
    public function getModuleName(): string;
    /** Returns the canonical name of the view being rendered. */
    public function getViewName(): string;       // canonical view name
    /** Returns the lowercase name of the output type the view renders for. */
    public function getOutputTypeName(): string; // output type name (lowercase)
    /**
     * Returns the module of the action that selected this view.
     *
     * Null when the view was reached without an originating action, so callers
     * that need a module name should fall back to the view module.
     */
    public function getActionModuleName(): ?string; // originating action module (for slots/forwards)
    /** Returns the name of the action that selected this view, or null when there was none. */
    public function getActionName(): ?string;       // originating action name
    /**
     * @return array<string, mixed>
     */
    public function getActionAttributes(): array;   // snapshot of action attributes (read-only for templates)
    /** Returns the response the view writes its rendered output into. */
    public function getResponse(): WebResponse;   // canonical web response
    /**
     * Optional PSR-7 response adapter backing the legacy response.
     * Views may use this when interacting with PSR-aware middleware or code.
     * @return ResponseInterface|null
     */
    public function getPsrResponse(): ?ResponseInterface;
    /**
     * Returns the validation manager carrying this dispatch's error state, or null
     * when none is available.
     */
    public function getValidationManager(): ?ValidationManager;
}
