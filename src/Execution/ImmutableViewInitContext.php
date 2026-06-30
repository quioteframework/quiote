<?php

namespace Agavi\Execution;

use Agavi\AgaviContext;
use Agavi\Response\AgaviWebResponse;
// note: keep runtime checks against AgaviWebResponse but avoid importing it here
use Psr\Http\Message\ResponseInterface;
use Agavi\Http\PsrResponseAdapter;
use Agavi\Util\AgaviAttributeHolder; // to expose action attributes via standard view API

final class ImmutableViewInitContext extends AgaviAttributeHolder implements ViewInitContext
{
    private ?object $validationManager;

    public function __construct(
        private AgaviContext $context,
        private string $viewModule,
        private string $viewName,
        private string $outputType,
        private ?string $actionModule,
        private ?string $actionName,
        private array $actionAttributes,
    private AgaviWebResponse $response,
        private ?ResponseInterface $psrResponse = null,
        ?object $validationManager = null
    ) {
        $this->validationManager = $validationManager;
        // Populate attribute holder with snapshot so AgaviView::getAttribute()/getAttributes() work.
        // Immutable semantics: later setAttribute() calls are ignored by AgaviView because initContext instanceof ViewInitContext.
        if (!empty($actionAttributes)) {
            // Use setAttributes so existing keys preserved if already set (should not be at construction time).
            $this->setAttributes($actionAttributes);
        }
    }

    public function getContext(): AgaviContext
    {
        return $this->context;
    }
    public function getViewModuleName(): string
    {
        return $this->viewModule;
    }
    public function getViewName(): string
    {
        return $this->viewName;
    }
    public function getOutputTypeName(): string
    {
        return $this->outputType;
    }
    public function getActionModuleName(): ?string
    {
        return $this->actionModule;
    }
    public function getActionName(): ?string
    {
        return $this->actionName;
    }
    public function getActionAttributes(): array
    {
        return $this->actionAttributes;
    }
    public function getResponse(): AgaviWebResponse
    {
        return $this->response;
    }

    public function getPsrResponse(): ?ResponseInterface
    {
        // If an explicit PSR response was provided use it; otherwise, if the
        // legacy response is an AgaviWebResponse wrap it lazily in the adapter
        if ($this->psrResponse !== null) {
            return $this->psrResponse;
        }
        if ($this->response instanceof \Agavi\Response\AgaviWebResponse) {
            $this->psrResponse = new PsrResponseAdapter($this->response);
            return $this->psrResponse;
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Legacy view compatibility layer
    // Several existing application views (and base classes) still assume a
    // "container" object exposing getParameter(), getModuleName(),
    // getActionName(), etc. Under the container-less pipeline we supply only
    // this immutable init context. To avoid fatals (e.g. setupHtml() calling
    // getParameter('is_slot', false)), we provide no-op shims for a minimal
    // subset of that API. These intentionally do NOT mutate internal state –
    // they merely return defaults so legacy conditionals fall through safely.
    // Once views are refactored to rely on the new attribute snapshot model
    // these can be removed.

    /**
     * Return action module name for legacy code that called getModuleName().
     */
    public function getModuleName(): ?string
    {
        return $this->actionModule ?? $this->viewModule;
    }

    /**
     * Return legacy-style output type object proxy requirement: legacy code
     * sometimes dereferenced $this->container->getOutputType()->getName(). We
     * can't cheaply recreate the output type object here without a controller
     * reference, so omit for now; views should use AgaviView::getCurrentOutputType().
     */
    public function getOutputType()
    {
        // Provide a tiny proxy object exposing getName() only.
        return new class($this->outputType) {
            public function __construct(private string $n) {}
            public function getName()
            {
                return $this->n;
            }
        };
    }

    /** Legacy accessor used by setupHtml() for action name (already defined above). */

    /**
     * Legacy parameter bag access – always returns $default (no parameters are
     * stored in immutable context). Slot/layout code that checks flags like
     * 'is_slot' will simply see the default (false) and continue.
     */
    public function &getParameter($name, $default = null)
    {
        // Return a reference to a static variable to avoid PHP notice about
        // returning non-variable references while preserving legacy by-ref
        // signature. Different callers should not rely on mutating this value.
        static $ref;
        $ref = $default;
        return $ref;
    }

    /**
     * Expose an empty parameter array for completeness.
     */
    public function &getParameters(): array
    {
        // Return reference to a static empty array to satisfy callers expecting
        // a by-reference return without triggering a notice.
        static $empty = [];
        $empty = [];
        return $empty;
    }

    /**
     * Return the validation manager, preferring the one injected at construction time (which
     * carries the live error state from the current request). Falls back to creating a fresh
     * instance only when none was supplied — callers that need error messages (e.g. JSON error
     * views) must pass the validation manager explicitly via the constructor.
     */
    public function getValidationManager()
    {
        if ($this->validationManager !== null) {
            return $this->validationManager;
        }
        try {
            return $this->context->createInstanceFor('validation_manager');
        } catch (\Throwable) {
            return null;
        }
    }
}
