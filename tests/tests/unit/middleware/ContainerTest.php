<?php
use PHPUnit\Framework\TestCase;
use Quiote\DI\Container;
use Quiote\DI\Attribute\Autowire;
use Quiote\DI\Attribute\Inject;
use Quiote\DI\Attribute\Service;
use Symfony\Contracts\Service\Attribute\Required;

class ContainerTest extends TestCase
{
    public function testAutoWireSimple(): void
    {
        $c = new Container();
        $c->set(DateTime::class, fn()=> new DateTime('2025-01-01'));
        $dt = $c->get(DateTime::class);
        $this->assertInstanceOf(DateTime::class, $dt);
    }

    public function testClosureDefinition(): void
    {
        $c = new Container();
        $c->set('val', fn()=> new stdClass());
        $v1 = $c->get('val');
        $v2 = $c->get('val');
        $this->assertSame($v1, $v2, 'Should be cached singleton');
    }

    public function testClassAutowireMissingDepFallsBackNull(): void
    {
        $this->expectException(\Quiote\DI\ContainerException::class);
        $c = new Container();
        $c->get(ContainerAutowireFixture::class);
    }

    public function testAliasAndFactory(): void
    {
        $c = new Container();
        $c->alias('clock', DateTimeImmutable::class);
        $c->setFactory(DateTimeImmutable::class, fn()=> new DateTimeImmutable('2025-01-02T00:00:00Z'));
        $dt = $c->get('clock');
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
        $this->assertEquals('2025-01-02T00:00:00+00:00', $dt->format('c'));
    }

    public function testTransientScopeNeverCaches(): void
    {
        $c = new Container();
        $c->set('val', fn() => new stdClass(), Container::SCOPE_TRANSIENT);
        $v1 = $c->get('val');
        $v2 = $c->get('val');
        $this->assertNotSame($v1, $v2, 'Transient scope must build a fresh instance every time');
    }

    public function testRequestScopeCachesWithinRequestButNotAcrossReset(): void
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

    public function testResetDoesNotAffectSingletons(): void
    {
        $c = new Container();
        $c->set('val', fn() => new stdClass()); // default scope: singleton
        $v1 = $c->get('val');
        $c->reset();
        $v2 = $c->get('val');
        $this->assertSame($v1, $v2, 'reset() must not drop singleton-scoped instances');
    }

    public function testParameterBindingInjectsScalarValues(): void
    {
        $c = new Container();
        $c->set(ContainerParamFixture::class, ContainerParamFixture::class, Container::SCOPE_SINGLETON, ['name' => 'cookie_name', 'mode' => 'strict']);
        $obj = $c->get(ContainerParamFixture::class);
        $this->assertSame('cookie_name', $obj->name);
        $this->assertSame('strict', $obj->mode);
    }

    public function testCycleDetectionThrows(): void
    {
        $c = new Container();
        $c->set(ContainerCycleA::class, ContainerCycleA::class);
        $c->set(ContainerCycleB::class, ContainerCycleB::class);
        $this->expectException(\Quiote\DI\ContainerException::class);
        $this->expectExceptionMessageMatches('/Circular dependency/');
        $c->get(ContainerCycleA::class);
    }

    public function testHasIsHonestAboutRegisteredEntriesOnly(): void
    {
        $c = new Container();
        $this->assertFalse($c->has(DateTime::class), 'has() must not report true for a merely-autowireable class');
        $c->set(DateTime::class, fn() => new DateTime('2025-01-01'));
        $this->assertTrue($c->has(DateTime::class));
        $c->alias('clock', DateTimeImmutable::class);
        $this->assertTrue($c->has('clock'));
    }

    public function testUnregisteredAutowireableClassStillResolvesViaGet(): void
    {
        $c = new Container();
        $this->assertFalse($c->has(ContainerNoDepsFixture::class));
        $obj = $c->get(ContainerNoDepsFixture::class);
        $this->assertInstanceOf(ContainerNoDepsFixture::class, $obj);
    }

    public function testRequiredMethodIsInvokedWithAutowiredArgs(): void
    {
        $c = new Container();
        $c->set('clock', fn() => new DateTimeImmutable('2025-01-02T00:00:00Z'));
        $c->alias(DateTimeImmutable::class, 'clock');
        $obj = $c->get(ContainerRequiredSetterFixture::class);
        $this->assertInstanceOf(DateTimeImmutable::class, $obj->clock);
    }

    public function testRequiredMethodNamedInitializeIsRejected(): void
    {
        $c = new Container();
        $this->expectException(\Quiote\DI\ContainerException::class);
        $this->expectExceptionMessageMatches("/'initialize\(\)' is a framework lifecycle hook/");
        $c->get(ContainerRequiredInitializeFixture::class);
    }

    public function testRequiredMethodTypeHintingActionInitContextIsRejectedRegardlessOfName(): void
    {
        $c = new Container();
        $this->expectException(\Quiote\DI\ContainerException::class);
        $this->expectExceptionMessageMatches('/ActionInitContext/');
        $c->get(ContainerRequiredWrongNameButForbiddenTypeFixture::class);
    }

    public function testServiceAttributeSetsDefaultScopeForUnregisteredClass(): void
    {
        $c = new Container();
        $v1 = $c->get(ContainerTransientServiceFixture::class);
        $v2 = $c->get(ContainerTransientServiceFixture::class);
        $this->assertNotSame($v1, $v2, '#[Service(scope: transient)] must be honored for an unregistered, autowired class');
    }

    public function testInjectAttributeOverridesAutowiringByType(): void
    {
        $c = new Container();
        $c->set('primary.clock', fn() => new DateTimeImmutable('2025-01-02T00:00:00Z'));
        $obj = $c->get(ContainerInjectFixture::class);
        $this->assertSame('2025-01-02T00:00:00+00:00', $obj->clock->format('c'));
    }

    public function testAutowireAttributeInjectsLiteralValue(): void
    {
        $c = new Container();
        $obj = $c->get(ContainerAutowireAttributeFixture::class);
        $this->assertSame('cookie_name', $obj->name);
    }

    public function testQuioteServiceInterfaceDefaultsToTransientWithoutServiceAttribute(): void
    {
        $c = new Container();
        $v1 = $c->get(ContainerPlainServiceFixture::class);
        $v2 = $c->get(ContainerPlainServiceFixture::class);
        $this->assertNotSame($v1, $v2, 'ServiceInterface implementors must default to transient scope, not singleton');
    }

    public function testServiceAttributeOverridesQuioteServiceInterfaceDefault(): void
    {
        $c = new Container();
        $v1 = $c->get(ContainerSingletonServiceFixture::class);
        $v2 = $c->get(ContainerSingletonServiceFixture::class);
        $this->assertSame($v1, $v2, '#[Service(scope: singleton)] must override the ServiceInterface transient default');
    }

    public function testMakeNeverCachesEvenForOtherwiseSingletonClass(): void
    {
        $c = new Container();
        $v1 = $c->make(ContainerNoDepsFixture::class);
        $v2 = $c->make(ContainerNoDepsFixture::class);
        $this->assertNotSame($v1, $v2, 'make() must build a fresh instance every call, regardless of scope policy');
    }

    public function testMakeWithNoConstructorBehavesLikePlainNew(): void
    {
        $c = new Container();
        $obj = $c->make(ContainerNoDepsFixture::class);
        $this->assertInstanceOf(ContainerNoDepsFixture::class, $obj);
    }

    public function testMakeAutowiresConstructorDependencies(): void
    {
        $c = new Container();
        $c->set('clock', fn() => new DateTimeImmutable('2025-01-02T00:00:00Z'));
        $c->alias(DateTimeImmutable::class, 'clock');
        $obj = $c->make(ContainerMakeFixture::class);
        if (!$obj instanceof ContainerMakeFixture) {
            throw new \RuntimeException('Expected ContainerMakeFixture instance');
        }
        $this->assertInstanceOf(DateTimeImmutable::class, $obj->clock);
    }

    public function testMakeExtraParamsOverrideByParameterName(): void
    {
        $c = new Container();
        $obj = $c->make(ContainerParamFixture::class, ['name' => 'override_name']);
        if (!$obj instanceof ContainerParamFixture) {
            throw new \RuntimeException('Expected ContainerParamFixture instance');
        }
        $this->assertSame('override_name', $obj->name);
    }

    public function testMakeExtraParamsOverrideByType(): void
    {
        $c = new Container();
        $override = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $obj = $c->make(ContainerMakeFixture::class, [DateTimeImmutable::class => $override]);
        if (!$obj instanceof ContainerMakeFixture) {
            throw new \RuntimeException('Expected ContainerMakeFixture instance');
        }
        $this->assertSame($override, $obj->clock);
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
    public function setUp(\Quiote\Execution\ActionInitContext $ctx): void
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

class ContainerPlainServiceFixture implements \Quiote\Service\ServiceInterface
{
}

#[Service(scope: Container::SCOPE_SINGLETON)]
class ContainerSingletonServiceFixture implements \Quiote\Service\ServiceInterface
{
}

class ContainerMakeFixture
{
    public function __construct(public DateTimeImmutable $clock) {}
}
