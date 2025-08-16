<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\SlotRequestFactory;
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

    private function dispatchComplex(array $params=[], ?string $outputType=null): string
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache','CacheComplex', $params, $outputType);
        return $dispatcher->dispatch($slotReq, 'Cache','CacheComplex', $params, $outputType);
    }

    public function testSuccessPath()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,false);
        $content = $this->dispatchComplex();
        $this->assertSame('<div>COMPLEX_OK</div>', $content);
    }

    public function testValidationFailureTriggersErrorView()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(true,false,false);
        $content = $this->dispatchComplex();
    $this->assertSame('<div>COMPLEX_ERROR</div>', $content);
    }

    public function testRequiresAuthRedirectsLogin()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,true,false);
        // ensure user logged out
        $user = $this->getContext()->getUser();
        if(method_exists($user,'setAuthenticated')) { $user->setAuthenticated(false); }
        $content = $this->dispatchComplex();
        $this->assertSame('<div>LOGIN_REQUIRED</div>', $content, 'Expected login forward view content not produced');
    }

    public function testRequiresCredentialTriggersSecureForward()
    {
    \Sandbox\Modules\Cache\Actions\CacheComplexAction::configure(false,false,true);
        // remove credential
        $user = $this->getContext()->getUser();
        if(method_exists($user,'removeCredential')) { $user->removeCredential('complex_cred'); }
        $content = $this->dispatchComplex();
        $this->assertSame('<div>SECURE_REQUIRED</div>', $content, 'Expected secure forward view content not produced');
    }
}
