<?php
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotRequestFactory;
use Quiote\Execution\SlotExecutionContext;
use Quiote\Execution\SlotStack;
use Quiote\Middleware\SlotMiddleware;

/**
 * Tests experimental container-less path for non-simple slot actions (validation + security basics).
 */
class SlotNonSimpleNoContainerTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // These tests assert the freshly-rendered output of each dispatch and must
        // not see a cached payload. CacheComplexAction is cacheable, so if another
        // test left the action/slot cache enabled (core.use_cache), the error-path
        // method would cache COMPLEX_ERROR and the success-path method would replay
        // it. Force caching off and clear the shared cache for determinism.
        \Quiote\Config\Config::set('core.use_cache', false);
        \Quiote\Config\Config::set('core.cache_enabled', false);
        \Quiote\Cache\CacheManager::reset();
        // Start from a fresh request so parameters injected by a prior test (e.g.
        // SlotNonSimpleParityTest dispatches CacheComplex with fail=1) cannot leak
        // in via the shared context request and trip CacheComplexAction::validate().
        $fresh = new \Quiote\Request\WebRequest();
        $fresh->initialize($this->getContext());
        $this->getContext()->setRequest($fresh);
        putenv('QUIOTE_SLOT_NO_CONTAINER_ALL=1');
        // Ensure user has credential for baseline success
        $user = $this->getContext()->getUser();
        if(method_exists($user,'addCredential')) { $user->addCredential('complex_cred'); }
    // Baseline: authenticated so credential removal path triggers secure (not login) forward
    if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(true); }
        // preload action class
        $this->getContext()->getController()->createActionInstance('Cache','CacheComplex');
    }
    protected function tearDown(): void
    {
        putenv('QUIOTE_SLOT_NO_CONTAINER_ALL');
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dispatchComplex(array $params = [], ?string $outputType = null): SlotExecutionContext
    {
        $parent = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache', 'CacheComplex', $params, $outputType);
        return $dispatcher->dispatchSlotContext($slotReq, 'Cache', 'CacheComplex', $params, $outputType);
    }

    public function testSuccessPath(): void
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
    $ctx = $this->dispatchComplex();
    $this->assertSame('<div>COMPLEX_OK</div>', $ctx->content);
    }

    public function testValidationFailureTriggersErrorView(): void
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true,false,false);
        $ctx = $this->dispatchComplex();
    $this->assertSame('<div>COMPLEX_ERROR</div>', $ctx->content);
    }

    public function testRequiresAuthRedirectsLogin(): void
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,true,false);
        // ensure user logged out
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(false); }
    $ctx = $this->dispatchComplex();
    $this->assertSame('', $ctx->content, 'Security denial should suppress slot content');
    $this->assertNull($ctx->view, 'No view should be rendered for security-denied slot');
    }

    public function testRequiresCredentialTriggersSecureForward(): void
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,true);
        // remove credential
        $user = $this->getContext()->getUser();
        if(method_exists($user,'removeCredential')) { $user->removeCredential('complex_cred'); }
    $ctx = $this->dispatchComplex();
    $this->assertSame('', $ctx->content, 'Security denial should suppress slot content');
    $this->assertNull($ctx->view, 'No view should be rendered for security-denied slot');
    }
}
