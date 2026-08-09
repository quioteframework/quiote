<?php

namespace Quiote\Execution;

use Quiote\Context;
use Quiote\Response\WebResponse;
use Quiote\Util\AttributeHolder;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The {@see ActionInitContext} every dispatch path constructs: the executor, the dispatch,
 * security and validation middleware, the slot dispatcher and the input-schema resolver.
 *
 * Holds the dispatch identity, request and response as readonly constructor state and adds
 * only what an action needs to write back: the view module and view name, plus the attribute
 * storage inherited from {@see AttributeHolder}, which is what a view later reads as the
 * action's attributes.
 *
 * The validation manager is resolved lazily from the container and cached, and can be
 * replaced with {@see setValidationManager()} so an action's own `validate*()` methods see
 * the same errors and exports as the XML validators that already ran.
 */
class LightweightActionInitContext extends AttributeHolder implements ActionInitContext
{
    private ?string $viewModuleName = null;
    private ?string $viewName = null;
    private ?object $validationManager = null;

    public function __construct(
        private readonly Context $context,
        private readonly string $module,
        private readonly string $action,
        private readonly string $method,
        private readonly string $outputType,
        /**
         * Accept WebRequest (implements ServerRequestInterface) or any PSR-7 ServerRequest.
         */
    private readonly ServerRequestInterface|null $requestData,
    private readonly WebResponse $response
    ) {}

    /** {@inheritDoc} */
    public function getContext(): Context
    {
        return $this->context;
    }
    /** {@inheritDoc} */
    public function getModuleName(): string
    {
        return $this->module;
    }
    /** {@inheritDoc} */
    public function getActionName(): string
    {
        return $this->action;
    }
    /** {@inheritDoc} */
    public function getRequestMethod(): string
    {
        return $this->method;
    }
    /** {@inheritDoc} */
    public function getOutputTypeName(): string
    {
        return $this->outputType;
    }
    /** {@inheritDoc} */
    public function getRequestData(): ?ServerRequestInterface
    {
        return $this->requestData;
    }
    /** {@inheritDoc} */
    public function getResponse(): WebResponse
    {
        return $this->response;
    }
    /** {@inheritDoc} */
    public function setViewModuleName(?string $module): void
    {
        $this->viewModuleName = $module;
    }
    /** {@inheritDoc} */
    public function setViewName(?string $name): void
    {
        $this->viewName = $name;
    }
    /** {@inheritDoc} */
    public function getViewModuleName(): ?string
    {
        return $this->viewModuleName;
    }
    /** {@inheritDoc} */
    public function getViewName(): ?string
    {
        return $this->viewName;
    }

    /**
     * Returns the validation manager shared by this dispatch, or null when none can be resolved.
     *
     * The first successful lookup goes through the context's container and is cached on this
     * instance, so XML validators, the action's own validate*() methods and error-handling code
     * all observe the same error and export state. A container that cannot supply one — or that
     * throws while trying — yields null rather than propagating, and the lookup is retried on the
     * next call.
     */
    public function getValidationManager(): ?object
    {
        if ($this->validationManager !== null) {
            return $this->validationManager;
        }
        try {
            $this->validationManager = $this->context->getContainer()->tryGet(\Quiote\Validator\ValidationManager::class);
            return $this->validationManager;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Replace the cached validation manager.
     * Called by ValidationService / ActionTestCase to inject the VM that
     * XML validators were executed against, so that the action's manual
     * validate*() methods see the same errors and exports.
     */
    public function setValidationManager(?object $vm): void
    {
        $this->validationManager = $vm;
    }
}
