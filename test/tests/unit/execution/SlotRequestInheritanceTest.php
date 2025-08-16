<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Agavi\Execution\SlotRequestFactory;
use Agavi\Execution\SlotDispatcher;
use Agavi\Execution\SlotStack;
use Agavi\Middleware\SlotMiddleware;

class SlotRequestInheritanceTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getContext()->getController()->initializeModule('Cache');
        $this->getContext()->getController()->createActionInstance('Cache','Cache');
    }

    public function testParentHeadersAndMethodPreserved()
    {
        $parent = (new ServerRequest('POST', new Uri('https://example.org/base')))
            ->withHeader('X-Custom','123')
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack())
            ->withAttribute('parentAttr','value');
        $child = SlotRequestFactory::create($parent, 'Cache', 'Cache', ['p'=>1], 'Html');
        // Ensure original request unchanged
        $this->assertNull($parent->getAttribute(SlotRequestFactory::ATTR_SLOT_MODULE));
        // Ensure child retains method & headers
        $this->assertSame('POST', $child->getMethod());
        $this->assertSame(['123'], $child->getHeader('X-Custom'));
        $this->assertSame('value', $child->getAttribute('parentAttr'));
    }

    public function testOutputTypeOverrideAttribute()
    {
        $parent = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $child = SlotRequestFactory::create($parent, 'Cache', 'Cache', [], 'Html');
        $this->assertSame('Html', $child->getAttribute(SlotRequestFactory::ATTR_SLOT_OUTPUTTYPE));
    }
}
