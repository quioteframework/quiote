<?php
namespace Test\Execution;

use Quiote\Execution\SlotContent;
use PHPUnit\Framework\TestCase;

class SlotContentValueObjectTest extends TestCase
{
    public function testBasicProperties()
    {
        $sc = new SlotContent('Foo', 'Bar', 'html', '<p>Hi</p>', ['a' => 1]);
        $this->assertSame('Foo', $sc->getModule());
        $this->assertSame('Bar', $sc->getAction());
        $this->assertSame('html', $sc->getOutputType());
        $this->assertSame('<p>Hi</p>', $sc->getContent());
        $this->assertSame(['a' => 1], $sc->getArguments());
        $this->assertStringContainsString('content_length', json_encode($sc->toArray()));
        $this->assertSame('<p>Hi</p>', (string)$sc);
    }
}
