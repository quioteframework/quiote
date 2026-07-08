<?php
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Quiote\Execution\SlotRequestFactory;
use Quiote\Execution\SlotDispatcher;
use Quiote\Execution\SlotStack;
use Quiote\Middleware\SlotMiddleware;

class SlotRequestInheritanceTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getContext()->getController()->initializeModule('Cache');
        $this->getContext()->getController()->createActionInstance('Cache','Cache');
    }

    public function testParentHeadersAndMethodPreserved(): void
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

    public function testOutputTypeOverrideAttribute(): void
    {
        $parent = (new ServerRequest('GET', 'http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $child = SlotRequestFactory::create($parent, 'Cache', 'Cache', [], 'Html');
        $this->assertSame('Html', $child->getAttribute(SlotRequestFactory::ATTR_SLOT_OUTPUTTYPE));
    }
}
