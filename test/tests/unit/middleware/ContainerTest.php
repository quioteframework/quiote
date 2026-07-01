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

    public function testTransientScopeNeverCaches()
    {
        $c = new Container();
        $c->set('val', fn() => new stdClass(), Container::SCOPE_TRANSIENT);
        $v1 = $c->get('val');
        $v2 = $c->get('val');
        $this->assertNotSame($v1, $v2, 'Transient scope must build a fresh instance every time');
    }

    public function testRequestScopeCachesWithinRequestButNotAcrossReset()
    {
        $c = new Container();
        $c->set('val', fn() => new stdClass(), Container::SCOPE_REQUEST);
        $v1 = $c->get('val');
        $v2 = $c->get('val');
        $this->assertSame($v1, $v2, 'Request scope should cache within the same request');

        $c->reset();
        $v3 = $c->get('val');
        $this->assertNotSame($v1, $v3, 'reset() must drop request-scoped instances');
    }

    public function testResetDoesNotAffectSingletons()
    {
        $c = new Container();
        $c->set('val', fn() => new stdClass()); // default scope: singleton
        $v1 = $c->get('val');
        $c->reset();
        $v2 = $c->get('val');
        $this->assertSame($v1, $v2, 'reset() must not drop singleton-scoped instances');
    }

    public function testParameterBindingInjectsScalarValues()
    {
        $c = new Container();
        $c->set(ContainerParamFixture::class, ContainerParamFixture::class, Container::SCOPE_SINGLETON, ['name' => 'cookie_name', 'mode' => 'strict']);
        $obj = $c->get(ContainerParamFixture::class);
        $this->assertSame('cookie_name', $obj->name);
        $this->assertSame('strict', $obj->mode);
    }

    public function testCycleDetectionThrows()
    {
        $c = new Container();
        $c->set(ContainerCycleA::class, ContainerCycleA::class);
        $c->set(ContainerCycleB::class, ContainerCycleB::class);
        $this->expectException(\Agavi\DI\ContainerException::class);
        $this->expectExceptionMessageMatches('/Circular dependency/');
        $c->get(ContainerCycleA::class);
    }

    public function testHasIsHonestAboutRegisteredEntriesOnly()
    {
        $c = new Container();
        $this->assertFalse($c->has(DateTime::class), 'has() must not report true for a merely-autowireable class');
        $c->set(DateTime::class, fn() => new DateTime('2025-01-01'));
        $this->assertTrue($c->has(DateTime::class));
        $c->alias('clock', DateTimeImmutable::class);
        $this->assertTrue($c->has('clock'));
    }

    public function testUnregisteredAutowireableClassStillResolvesViaGet()
    {
        $c = new Container();
        $this->assertFalse($c->has(ContainerNoDepsFixture::class));
        $obj = $c->get(ContainerNoDepsFixture::class);
        $this->assertInstanceOf(ContainerNoDepsFixture::class, $obj);
    }
}

class ContainerParamFixture
{
    public function __construct(public string $name, public string $mode = 'lax') {}
}

class ContainerNoDepsFixture
{
}

class ContainerCycleA
{
    public function __construct(public ContainerCycleB $b) {}
}

class ContainerCycleB
{
    public function __construct(public ContainerCycleA $a) {}
}

class ContainerAutowireFixture
{
    public function __construct(public ?SplFileInfo $dep = null) {}
}
