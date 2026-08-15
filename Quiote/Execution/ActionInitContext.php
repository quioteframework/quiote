<?php

namespace Quiote\Execution;

use Quiote\Context;
use Quiote\Response\WebResponse;
use Quiote\Validator\ValidationManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What an action is handed by `Action::initialize()`: the identity of the dispatch it is
 * running under, the request and response it works with, and the slot for the view it wants
 * rendered.
 *
 * Constructed by whoever is dispatching — the executor, the middleware pipeline, the slot
 * dispatcher — and never resolved from the container, which does not own per-execution
 * state and refuses to autowire this type into a `#[Required]` method.
 *
 * Implementors must answer the module, action, request method and output type of the current
 * dispatch, expose the {@see \Quiote\Context} and the {@see WebResponse} being written into,
 * and remember the view module and view name set on them so the dispatcher can read them
 * back after the action returns. Attribute storage comes from
 * {@see \Quiote\Util\AttributeHolder} on the implementations rather than from this interface.
 */
interface ActionInitContext
{
    /** Returns the application Context the action is executing under. */
    public function getContext(): Context;
    /** Returns the name of the module the action was dispatched from. */
    public function getModuleName(): string;
    /** Returns the name of the action being executed. */
    public function getActionName(): string;
    /** Returns the request method token the action was dispatched with. */
    public function getRequestMethod(): string;
    /** Returns the name of the output type selected for this dispatch. */
    public function getOutputTypeName(): string;
    /**
     * Returns the PSR-7 server request backing this dispatch.
     *
     * Null when the action is executed without a request, as in a slot or test
     * dispatch assembled directly rather than from an incoming HTTP request.
     */
    public function getRequestData(): ?ServerRequestInterface;
    /** Returns the response the action and its view write into. */
    public function getResponse(): WebResponse;
    // Attribute methods inherited from AttributeHolder via LightweightActionInitContext extension; intentionally not part of strict interface to avoid signature conflicts.
    /**
     * Records the module hosting the view to render, overriding the action's own module.
     *
     * Passing null clears the override so the action's module is used.
     */
    public function setViewModuleName(?string $module): void;
    /**
     * Records the name of the view to render for this action.
     *
     * Passing null clears the selection, leaving the view to be resolved from the
     * action's own return value.
     */
    public function setViewName(?string $name): void;
    /** Returns the view module recorded by setViewModuleName(), or null when none was set. */
    public function getViewModuleName(): ?string;
    /** Returns the view name recorded by setViewName(), or null when none was set. */
    public function getViewName(): ?string;

    /**
     * Returns the validation manager carrying this dispatch's error state, or null when none
     * is available.
     */
    public function getValidationManager(): ?ValidationManager;

}
