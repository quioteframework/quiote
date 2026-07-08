<?php
namespace Test\Execution;

use Quiote\Execution\SlotContent;
use PHPUnit\Framework\TestCase;

class SlotContentValueObjectTest extends TestCase
{
    public function testBasicProperties(): void
    {
        $sc = new SlotContent('Foo', 'Bar', 'html', '<p>Hi</p>', ['a' => 1]);
        $this->assertSame('Foo', $sc->getModule());
        $this->assertSame('Bar', $sc->getAction());
        $this->assertSame('html', $sc->getOutputType());
        $this->assertSame('<p>Hi</p>', $sc->getContent());
        $this->assertSame(['a' => 1], $sc->getArguments());
        $encoded = json_encode($sc->toArray());
        $this->assertIsString($encoded, 'toArray() must be JSON-encodable');
        $this->assertStringContainsString('content_length', $encoded);
        $this->assertSame('<p>Hi</p>', (string)$sc);
    }
}
