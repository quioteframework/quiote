<?php
namespace Agavi\Execution;

use Agavi\AgaviContext;
use Agavi\Response\AgaviResponse;

final class ImmutableViewInitContext implements ViewInitContext
{
    public function __construct(
        private AgaviContext $context,
        private string $viewModule,
        private string $viewName,
        private string $outputType,
        private ?string $actionModule,
        private ?string $actionName,
        private array $actionAttributes,
        private AgaviResponse $response
    ) {}

    public function getContext(): AgaviContext { return $this->context; }
    public function getViewModuleName(): string { return $this->viewModule; }
    public function getViewName(): string { return $this->viewName; }
    public function getOutputTypeName(): string { return $this->outputType; }
    public function getActionModuleName(): ?string { return $this->actionModule; }
    public function getActionName(): ?string { return $this->actionName; }
    public function getActionAttributes(): array { return $this->actionAttributes; }
    public function getResponse(): AgaviResponse { return $this->response; }

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
    public function getModuleName(): ?string { return $this->actionModule ?? $this->viewModule; }

    /**
     * Return legacy-style output type object proxy requirement: legacy code
     * sometimes dereferenced $this->container->getOutputType()->getName(). We
     * can't cheaply recreate the output type object here without a controller
     * reference, so omit for now; views should use AgaviView::getCurrentOutputType().
     */
    public function getOutputType() {
        // Provide a tiny proxy object exposing getName() only.
        return new class($this->outputType) { public function __construct(private string $n){} public function getName(){ return $this->n; } };
    }

    /** Legacy accessor used by setupHtml() for action name (already defined above). */

    /**
     * Legacy parameter bag access – always returns $default (no parameters are
     * stored in immutable context). Slot/layout code that checks flags like
     * 'is_slot' will simply see the default (false) and continue.
     */
    public function getParameter(string $name, $default = null) { return $default; }

    /**
     * Expose an empty parameter array for completeness.
     */
    public function getParameters(): array { return []; }
}
