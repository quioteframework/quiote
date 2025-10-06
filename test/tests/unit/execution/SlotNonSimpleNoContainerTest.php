<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\SlotRequestFactory;
use Agavi\Execution\SlotExecutionContext;
use Agavi\Execution\SlotStack;
use Agavi\Middleware\SlotMiddleware;

/**
 * Tests experimental container-less path for non-simple slot actions (validation + security basics).
 */
class SlotNonSimpleNoContainerTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('AGAVI_SLOT_NO_CONTAINER_ALL=1');
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
        putenv('AGAVI_SLOT_NO_CONTAINER_ALL');
        parent::tearDown();
    }

    private function dispatchComplex(array $params = [], ?string $outputType = null): SlotExecutionContext
    {
        $parent = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache', 'CacheComplex', $params, $outputType);
        return $dispatcher->dispatchSlotContext($slotReq, 'Cache', 'CacheComplex', $params, $outputType);
    }

    public function testSuccessPath()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
    $ctx = $this->dispatchComplex();
    $this->assertSame('<div>COMPLEX_OK</div>', $ctx->content);
    }

    public function testValidationFailureTriggersErrorView()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true,false,false);
        $ctx = $this->dispatchComplex();
    $this->assertSame('<div>COMPLEX_ERROR</div>', $ctx->content);
    }

    public function testRequiresAuthRedirectsLogin()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,true,false);
        // ensure user logged out
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(false); }
    $ctx = $this->dispatchComplex();
    $this->assertSame('', $ctx->content, 'Security denial should suppress slot content');
    $this->assertNull($ctx->view, 'No view should be rendered for security-denied slot');
    }

    public function testRequiresCredentialTriggersSecureForward()
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
