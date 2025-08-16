<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\SlotRequestFactory;
use Agavi\Execution\SlotStack;

class SlotRequestFactoryTest extends AgaviUnitTestCase
{
    public function testCreatesChildRequestWithMetadata()
    {
        $base = new ServerRequest('POST', 'http://example.test/path?x=1');
        $slotReq = SlotRequestFactory::create($base, 'Foo', 'Bar', ['a' => 123], 'Html');
        $this->assertSame('Foo', $slotReq->getAttribute(SlotRequestFactory::ATTR_SLOT_MODULE));
        $this->assertSame('Bar', $slotReq->getAttribute(SlotRequestFactory::ATTR_SLOT_ACTION));
        $this->assertSame(['a' => 123], $slotReq->getAttribute(SlotRequestFactory::ATTR_SLOT_PARAMETERS));
        $this->assertSame('Html', $slotReq->getAttribute(SlotRequestFactory::ATTR_SLOT_OUTPUTTYPE));
        $this->assertInstanceOf(SlotStack::class, $slotReq->getAttribute(SlotStack::class));
        // Original request untouched
        $this->assertNull($base->getAttribute(SlotRequestFactory::ATTR_SLOT_MODULE));
    }

    public function testPreservesExistingSlotStack()
    {
        $stack = new SlotStack();
        $base = (new ServerRequest('GET', 'http://example.test/'))
            ->withAttribute(SlotStack::class, $stack);
        $slotReq = SlotRequestFactory::create($base, 'M', 'A');
        $this->assertSame($stack, $slotReq->getAttribute(SlotStack::class));
    }
}
