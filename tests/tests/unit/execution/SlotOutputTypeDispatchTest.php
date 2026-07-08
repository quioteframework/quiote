<?php
use Quiote\Testing\UnitTestCase;
use Nyholm\Psr7\ServerRequest;
use Quiote\Execution\SlotRequestFactory;
use Quiote\Execution\SlotDispatcher;
use Quiote\Execution\SlotStack;
use Quiote\Middleware\SlotMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;

class SlotOutputTypeDispatchTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $controller = $this->getContext()->getController();
        $controller->initializeModule('Cache');
        // Force load of new multi-output view class via action execution once
        $controller->createActionInstance('Cache','Cache');
    }

    /**
     * @return array<int, array{0: string, 1: string|null}>
     */
    public static function outputTypeProvider(): array
    {
        return [
            ['html','<div>CACHE_HTML</div>'],
            ['json', null], // will assert JSON structure instead of exact string
            ['xml','<cache status="ok" type="xml" />'],
        ];
    }

    #[DataProvider('outputTypeProvider')]
    public function testSlotDispatchesCorrectViewMethod(string $outputType, ?string $expected): void
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        // Use factory to create request; we pass outputType explicitly to dispatcher
        $slotReq = SlotRequestFactory::create($parent, 'Cache','Cache', [], $outputType);
        $content = $dispatcher->dispatch($slotReq, 'Cache','Cache', [], $outputType);
    if($outputType === 'json') {
            $this->assertJson($content);
            $decoded = json_decode((string) $content, true);
            $this->assertSame('json', $decoded['type']);
            $this->assertSame('cache', $decoded['variant']);
        } else {
            $this->assertSame($expected, $content, "Output type $outputType should produce expected content");
        }
    }

    public function testFallbackOutputType(): void
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        $slotReq = SlotRequestFactory::create($parent, 'Cache','Cache');
        $content = $dispatcher->dispatch($slotReq, 'Cache','Cache');
    // With no explicit output type provided, framework default may map to Html output
    $this->assertSame('<div>CACHE_HTML</div>', $content);
    }
    
    public function testUnsupportedOutputTypeFallsBackToGenericExecute(): void
    {
        $parent = (new ServerRequest('GET','http://localhost/'))
            ->withAttribute(SlotMiddleware::ATTR, new SlotStack());
        $dispatcher = $this->getContext()->getSlotDispatcher();
        // Request an output type that exists in the config? We pick one that does NOT exist: 'Xls'
        // Dispatcher will attempt executeXls(), not found, then fallback to execute().
        $slotReq = SlotRequestFactory::create($parent, 'Cache','Cache', [], 'Xls');
        $content = $dispatcher->dispatch($slotReq, 'Cache','Cache', [], 'Xls');
        $this->assertSame('CACHE_FALLBACK', $content, 'Unsupported output type should call generic execute()');
    }
}
