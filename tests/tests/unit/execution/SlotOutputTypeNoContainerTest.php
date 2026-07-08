<?php
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotRequestFactory;
use Quiote\Execution\SlotStack;
use Quiote\Middleware\SlotMiddleware;

/**
 * Verifies slot output type dispatch behaves identically with container-less simple action path enabled.
 */
class SlotOutputTypeNoContainerTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Enable experimental no-container path
        putenv('QUIOTE_SLOT_SIMPLE_NO_CONTAINER=1');
        // Warm action class (ensures autoload)
        $this->getContext()->getController()->createActionInstance('Cache','Cache');
    }

    protected function tearDown(): void
    {
        // Clean up flag to not leak
        putenv('QUIOTE_SLOT_SIMPLE_NO_CONTAINER');
        parent::tearDown();
    }

    /**
     * @return array<int, array{0: string, 1: string|\Closure}>
     */
    public static function outputTypeProvider(): array
    {
        return [
            ['Html','<div>CACHE_HTML</div>'],
            ['Json', function(string $content): void{
                \PHPUnit\Framework\Assert::assertJson($content);
                $d = json_decode($content,true);
                \PHPUnit\Framework\Assert::assertSame('json',$d['type']);
                \PHPUnit\Framework\Assert::assertSame('cache',$d['variant']);
            }],
            ['Xml','<cache status="ok" type="xml" />'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outputTypeProvider')]
    public function testDispatchesExpectedOutput(string $outputType, string|\Closure $expected): void
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache','Cache', [], $outputType);
        $content = $dispatcher->dispatch($slotReq, 'Cache','Cache', [], $outputType);
        if($expected instanceof \Closure) {
            $expected($content);
        } else {
            $this->assertSame($expected, $content);
        }
    }

    public function testDefaultFallbackHtml(): void
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache','Cache');
        $content = $dispatcher->dispatch($slotReq, 'Cache','Cache');
        $this->assertSame('<div>CACHE_HTML</div>', $content);
    }

    public function testUnsupportedTypeFallsBack(): void
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache','Cache', [], 'Xls');
        $content = $dispatcher->dispatch($slotReq, 'Cache','Cache', [], 'Xls');
        $this->assertSame('CACHE_FALLBACK', $content);
    }
}
