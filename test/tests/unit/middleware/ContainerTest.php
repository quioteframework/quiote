<?php
use PHPUnit\Framework\TestCase;
use Agavi\DI\Container;
use Agavi\DI\Attribute\Autowire;
use Agavi\DI\Attribute\Inject;
use Agavi\DI\Attribute\Service;
use Symfony\Contracts\Service\Attribute\Required;

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

    public function testRequiredMethodIsInvokedWithAutowiredArgs()
    {
        $c = new Container();
        $c->set('clock', fn() => new DateTimeImmutable('2025-01-02T00:00:00Z'));
        $c->alias(DateTimeImmutable::class, 'clock');
        $obj = $c->get(ContainerRequiredSetterFixture::class);
        $this->assertInstanceOf(DateTimeImmutable::class, $obj->clock);
    }

    public function testRequiredMethodNamedInitializeIsRejected()
    {
        $c = new Container();
        $this->expectException(\Agavi\DI\ContainerException::class);
        $this->expectExceptionMessageMatches("/'initialize\(\)' is a framework lifecycle hook/");
        $c->get(ContainerRequiredInitializeFixture::class);
    }

    public function testRequiredMethodTypeHintingActionInitContextIsRejectedRegardlessOfName()
    {
        $c = new Container();
        $this->expectException(\Agavi\DI\ContainerException::class);
        $this->expectExceptionMessageMatches('/ActionInitContext/');
        $c->get(ContainerRequiredWrongNameButForbiddenTypeFixture::class);
    }

    public function testServiceAttributeSetsDefaultScopeForUnregisteredClass()
    {
        $c = new Container();
        $v1 = $c->get(ContainerTransientServiceFixture::class);
        $v2 = $c->get(ContainerTransientServiceFixture::class);
        $this->assertNotSame($v1, $v2, '#[Service(scope: transient)] must be honored for an unregistered, autowired class');
    }

    public function testInjectAttributeOverridesAutowiringByType()
    {
        $c = new Container();
        $c->set('primary.clock', fn() => new DateTimeImmutable('2025-01-02T00:00:00Z'));
        $obj = $c->get(ContainerInjectFixture::class);
        $this->assertSame('2025-01-02T00:00:00+00:00', $obj->clock->format('c'));
    }

    public function testAutowireAttributeInjectsLiteralValue()
    {
        $c = new Container();
        $obj = $c->get(ContainerAutowireAttributeFixture::class);
        $this->assertSame('cookie_name', $obj->name);
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

class ContainerRequiredSetterFixture
{
    public ?DateTimeImmutable $clock = null;

    #[Required]
    public function setClock(DateTimeImmutable $clock): void
    {
        $this->clock = $clock;
    }
}

class ContainerRequiredInitializeFixture
{
    #[Required]
    public function initialize(): void
    {
    }
}

class ContainerRequiredWrongNameButForbiddenTypeFixture
{
    #[Required]
    public function setUp(\Agavi\Execution\ActionInitContext $ctx): void
    {
    }
}

#[Service(scope: Container::SCOPE_TRANSIENT)]
class ContainerTransientServiceFixture
{
}

class ContainerInjectFixture
{
    public function __construct(
        #[Inject('primary.clock')] public DateTimeImmutable $clock,
    ) {}
}

class ContainerAutowireAttributeFixture
{
    public function __construct(
        #[Autowire('cookie_name')] public string $name,
    ) {}
}
