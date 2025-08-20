<?php
namespace Agavi\Execution;

use Agavi\Controller\AgaviController;
use Agavi\View\AgaviView;
use Agavi\Request\AgaviRequestDataHolder;

/**
 * ForwardService: resolves forward targets (login / secure / custom) without creating a full execution container.
 * Phase 1: only system forwards used by security (login, secure).
 * Future: generalize to arbitrary forward tokens returned by actions (array/module override forms).
 */
final class ForwardService
{
    public function __construct(private AgaviController $controller, private ?ViewNameResolver $resolver = null, private ?ViewFactory $viewFactory = null)
    {
        $this->resolver ??= new ViewNameResolver();
        $this->viewFactory ??= new ViewFactory($controller);
    }

    /**
     * Create a view for a system forward (login or secure) returning tuple [AgaviView|null, viewModule, viewName, content].
     * Content is produced immediately (execute* run) so caller can short-circuit dispatch.
     */
    /**
     * Legacy temporary method (now deprecated) that tried to short-circuit by rendering a view.
     * Left in place for transitional callers; now simply delegates to descriptor path and returns empty content.
     * @deprecated Use createSystemForwardActionDescriptor instead and dispatch normally.
     */
    public function createSystemForwardView(string $forwardName, string $outputType, AgaviRequestDataHolder $rd): array
    {
        [$module,$action] = $this->resolveSystemAction($forwardName);
        if(getenv('AGAVI_DEBUG_FORWARD')) { error_log("[ForwardService] DEPRECATED createSystemForwardView forward=$forwardName -> $module/$action (no direct view render)"); }
        return [null,$module,'', ''];
    }

    /**
     * Return an ActionDescriptor for a system forward (login / secure) honoring settings.xml mappings.
     */
    public function createSystemForwardActionDescriptor(string $forwardName, string $httpMethod, string $outputType): ActionDescriptor
    {
        [$module,$action] = $this->resolveSystemAction($forwardName);
    // Honor legacy semantics (PUT => create) via centralized mapper
    $method = HttpMethodMapper::toActionMethod($httpMethod);
        return ActionDescriptor::fromController($this->controller, $module, $action, $method, strtolower($outputType));
    }

    private function resolveSystemAction(string $forwardName): array
    {
        $confKeyModule = 'actions.' . strtolower($forwardName) . '_module';
        $confKeyAction = 'actions.' . strtolower($forwardName) . '_action';
        $module = \Agavi\Config\AgaviConfig::get($confKeyModule, 'Default');
        $action = \Agavi\Config\AgaviConfig::get($confKeyAction, ucfirst($forwardName));
        return [$module,$action];
    }

}
?>
