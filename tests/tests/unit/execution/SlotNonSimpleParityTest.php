<?php
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotRequestFactory;
use Quiote\Execution\SlotStack;
use Quiote\Middleware\SlotMiddleware;

/**
 * Parity tests comparing container (legacy) vs no-container paths for non-simple slot action.
 */
class SlotNonSimpleParityTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Preload action class to ensure legacy naming alias is registered
        $this->getContext()->getController()->createActionInstance('Cache','CacheComplex');
        // Reset context request to avoid state pollution from prior tests
        $fresh = new \Quiote\Request\WebRequest();
        $fresh->initialize($this->getContext());
        $this->getContext()->setRequest($fresh);
    }
    /**
     * @param array<string, mixed> $params
     */
    private function dispatchWithFlag(bool $noContainer, callable $configure, array $params=[]): string
    {
        if($noContainer) { putenv('QUIOTE_SLOT_NO_CONTAINER_ALL=1'); } else { putenv('QUIOTE_SLOT_NO_CONTAINER_ALL'); }
        $configure(); // set static flags
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache','CacheComplex', $params, null);
        // Re-apply configuration just before dispatch in case container path recreated action instance
        $configure();
        return $dispatcher->dispatch($slotReq, 'Cache','CacheComplex', $params);
    }

    public function testSuccessParity(): void
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
    $legacy = $this->dispatchWithFlag(false, fn()=>\Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false));
    $noContainer = $this->dispatchWithFlag(true, fn()=>\Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false));
        $this->assertSame('<div>COMPLEX_OK</div>', $legacy);
        $this->assertSame($legacy, $noContainer, 'Success content mismatch between paths');
    }

    public function testValidationErrorParity(): void
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false); // ensure baseline
    // Strict validation: whitelist parameter used in validation failure scenario.
    // Context::getRequest() always returns a WebRequest, so no instanceof guard is needed.
    $ctxReq = $this->getContext()->getRequest();
    $this->getContext()->setRequest($ctxReq->enforceValidatedParameters(['fail']));
    $legacy = $this->dispatchWithFlag(false, function(): void{ \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true,false,false); }, ['fail'=>1]);
    $noContainer = $this->dispatchWithFlag(true, function(): void{ \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true,false,false); }, ['fail'=>1]);
    $this->assertSame('<div>COMPLEX_ERROR</div>', $legacy);
    $this->assertSame($legacy, $noContainer, 'Validation error content mismatch between paths');
    }

    public function testRequiresAuthParity(): void
    {
        // Ensure user is logged out to trigger login forward in both paths
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(false); }
    $configure = function(): void{ \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,true,false); };
        $legacy = $this->dispatchWithFlag(false, $configure);
        // reset user again for second dispatch to avoid state carry-over from potential forward handling
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(false); }
        $noContainer = $this->dispatchWithFlag(true, $configure);
        // System login forward content may differ verbatim by path, but both paths
        // must agree on whether they suppressed content (empty) or rendered a forward view.
        $this->assertSame($legacy === '', $noContainer === '', 'Login-forward emptiness must match between legacy and no-container paths');
    }

    public function testRequiresCredentialParity(): void
    {
        // User authenticated but missing credential should trigger secure forward parity
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(true); }
        if(method_exists($user,'removeCredential')) { $user->removeCredential('complex_cred'); }
    $configure = function(): void{ \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,true); };
        $legacy = $this->dispatchWithFlag(false, $configure);
        // Reset auth/credentials before second dispatch
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(true); }
        if(method_exists($user,'removeCredential')) { $user->removeCredential('complex_cred'); }
        $noContainer = $this->dispatchWithFlag(true, $configure);
        // Secure-forward content may differ verbatim by path, but both paths
        // must agree on whether they suppressed content (empty) or rendered a forward view.
        $this->assertSame($legacy === '', $noContainer === '', 'Secure-forward emptiness must match between legacy and no-container paths');
    }
}
