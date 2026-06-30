<?php
use Agavi\Testing\AgaviUnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Agavi\Execution\SlotRequestFactory;
use Agavi\Execution\SlotStack;
use Agavi\Middleware\SlotMiddleware;

/**
 * Verifies slot output type dispatch behaves identically with container-less simple action path enabled.
 */
class SlotOutputTypeNoContainerTest extends AgaviUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Enable experimental no-container path
        putenv('AGAVI_SLOT_SIMPLE_NO_CONTAINER=1');
        // Warm action class (ensures autoload)
        $this->getContext()->getController()->createActionInstance('Cache','Cache');
    }

    protected function tearDown(): void
    {
        // Clean up flag to not leak
        putenv('AGAVI_SLOT_SIMPLE_NO_CONTAINER');
        parent::tearDown();
    }

    public static function outputTypeProvider(): array
    {
        return [
            ['Html','<div>CACHE_HTML</div>'],
            ['Json', function($content): void{
                \PHPUnit\Framework\Assert::assertJson($content);
                $d = json_decode($content,true);
                \PHPUnit\Framework\Assert::assertSame('json',$d['type']);
                \PHPUnit\Framework\Assert::assertSame('cache',$d['variant']);
            }],
            ['Xml','<cache status="ok" type="xml" />'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outputTypeProvider')]
    public function testDispatchesExpectedOutput($outputType, $expected)
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache','Cache', [], $outputType);
        $content = $dispatcher->dispatch($slotReq, 'Cache','Cache', [], $outputType);
        if(is_callable($expected)) {
            $expected($content);
        } else {
            $this->assertSame($expected, $content);
        }
    }

    public function testDefaultFallbackHtml()
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache','Cache');
        $content = $dispatcher->dispatch($slotReq, 'Cache','Cache');
        $this->assertSame('<div>CACHE_HTML</div>', $content);
    }

    public function testUnsupportedTypeFallsBack()
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache','Cache', [], 'Xls');
        $content = $dispatcher->dispatch($slotReq, 'Cache','Cache', [], 'Xls');
        $this->assertSame('CACHE_FALLBACK', $content);
    }
}
