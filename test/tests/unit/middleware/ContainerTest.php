<?php
use PHPUnit\Framework\TestCase;
use Agavi\DI\Container;

class ContainerTest extends TestCase
{
    public function testAutoWireSimple()
    {
        $c = new Container();
        $c->set(DateTime::class, fn()=> new DateTime('2025-01-01'));
        $dt = $c->get(DateTime::class);
        $this->assertInstanceOf(DateTime::class, $dt);
    }

    public function testClosureDefinition()
    {
        $c = new Container();
        $c->set('val', fn()=> new stdClass());
        $v1 = $c->get('val');
        $v2 = $c->get('val');
        $this->assertSame($v1, $v2, 'Should be cached singleton');
    }

    public function testClassAutowireMissingDepFallsBackNull()
    {
        $this->expectException(\Agavi\DI\ContainerException::class);
        $c = new Container();
        $c->get(ContainerAutowireFixture::class);
    }

    public function testAliasAndFactory()
    {
        $c = new Container();
        $c->alias('clock', DateTimeImmutable::class);
        $c->setFactory(DateTimeImmutable::class, fn()=> new DateTimeImmutable('2025-01-02T00:00:00Z'));
        $dt = $c->get('clock');
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
        $this->assertEquals('2025-01-02T00:00:00+00:00', $dt->format('c'));
    }
}

class ContainerAutowireFixture
{
    public function __construct(public ?SplFileInfo $dep = null) {}
}
