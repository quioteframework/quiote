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
        // Named, not defaulted: a factory defaults to request scope, and this test is about what
        // reset() does to a singleton.
        $c->set('val', fn() => new stdClass(), Container::SCOPE_SINGLETON);
        $v1 = $c->get('val');
        $c->reset();
        $v2 = $c->get('val');
        $this->assertSame($v1, $v2, 'reset() must not drop singleton-scoped instances');
    }

    /**
     * An explicit registration must not change a class's lifetime.
     *
     * `set()` defaulted to `SCOPE_SINGLETON`, so the lifetime depended on *how* a class was registered
     * rather than on what the class declares: autowired, `ContainerSingletonServiceFixture` is a
     * singleton and `ContainerPlainServiceFixture` is transient, but registering either -- often just to
     * give it a second name -- promoted it to the process lifetime. Under a worker that is one request's
     * state served to every request after it.
     */
    public function testRegisteringAClassKeepsTheScopeTheClassDeclares(): void
    {
        $c = new Container();
        $c->set('singleton.alias', ContainerSingletonServiceFixture::class);
        $c->set('transient.alias', ContainerPlainServiceFixture::class);
        $c->set('request.alias', ContainerRequestScopedFixture::class);

        $this->assertSame(
            $c->get('singleton.alias'),
            $c->get('singleton.alias'),
            '#[Service(scope: singleton)] still means singleton when the class is registered',
        );
        $this->assertNotSame(
            $c->get('transient.alias'),
            $c->get('transient.alias'),
            'a ServiceInterface implementor stays transient when registered',
        );

        $first = $c->get('request.alias');
        $this->assertSame($first, $c->get('request.alias'), 'request scope is shared within a request');
        $c->reset();
        $this->assertNotSame($first, $c->get('request.alias'), 'and dropped at the boundary');
    }

    /**
     * A factory declares no lifetime, so request scope is what it gets: the answer that cannot outlive
     * its inputs, and the one the captive-dependency guard can still catch a singleton holding.
     */
    public function testAFactoryDefaultsToRequestScope(): void
    {
        $c = new Container();
        $c->setFactory('built', fn(): stdClass => new stdClass());

        $first = $c->get('built');
        $this->assertSame($first, $c->get('built'), 'shared within one request');

        $c->reset();
        $this->assertNotSame($first, $c->get('built'), 'and not across the boundary');
    }

    /**
     * An instance is one object the caller made and handed over, so there is no lifetime for the
     * container to choose -- and being a singleton is what lets a singleton hold it.
     */
    public function testAnInstanceDefaultsToSingletonScope(): void
    {
        $c = new Container();
        $instance = new stdClass();
        $c->set('given', $instance);

        $this->assertSame($instance, $c->get('given'));
        $c->reset();
        $this->assertSame($instance, $c->get('given'), 'reset() does not drop what it was handed');
    }

    /**
     * A bound value is not a service: it survives the request boundary like an instance does.
     */
    public function testAScalarDefaultsToSingletonScope(): void
    {
        $c = new Container();
        $c->set('cookie_name', 'JKSID');

        $c->reset();

        $this->assertSame('JKSID', $c->get('cookie_name'));
    }

    public function testParameterBindingInjectsScalarValues(): void
    {
        $c = new Container();
        $c->set(ContainerParamFixture::class, ContainerParamFixture::class, Container::SCOPE_SINGLETON, ['name' => 'cookie_name', 'mode' => 'strict']);
        $obj = $c->get(ContainerParamFixture::class);
        $this->assertInstanceOf(ContainerParamFixture::class, $obj);
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
        $this->assertInstanceOf(ContainerRequiredSetterFixture::class, $obj);
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

    public function testBareServiceAttributeDefaultsToTransientScope(): void
    {
        $c = new Container();
        $v1 = $c->get(ContainerBareServiceAttributeFixture::class);
        $v2 = $c->get(ContainerBareServiceAttributeFixture::class);
        $this->assertNotSame(
            $v1,
            $v2,
            'a bare #[Service] must default to transient: process lifetime is a claim to make explicitly, '
            . 'since a singleton default would serve one request\'s state to the next under a worker',
        );
    }

    /**
     * The attribute takes precedence over the ServiceInterface check, so its default has to agree with
     * what the interface infers. Otherwise adding #[Service] to an existing service for discoverability
     * would silently promote it to process lifetime.
     */
    public function testBareServiceAttributeDoesNotPromoteAServiceInterfaceImplementor(): void
    {
        $c = new Container();
        $v1 = $c->get(ContainerBareServiceAttributeOnInterfaceFixture::class);
        $v2 = $c->get(ContainerBareServiceAttributeOnInterfaceFixture::class);
        $this->assertNotSame($v1, $v2, 'a bare #[Service] must not override the ServiceInterface transient default');
    }

    public function testInjectAttributeOverridesAutowiringByType(): void
    {
        $c = new Container();
        $c->set('primary.clock', fn() => new DateTimeImmutable('2025-01-02T00:00:00Z'));
        $obj = $c->get(ContainerInjectFixture::class);
        $this->assertInstanceOf(ContainerInjectFixture::class, $obj);
        $this->assertSame('2025-01-02T00:00:00+00:00', $obj->clock->format('c'));
    }

    public function testAutowireAttributeInjectsLiteralValue(): void
    {
        $c = new Container();
        $obj = $c->get(ContainerAutowireAttributeFixture::class);
        $this->assertInstanceOf(ContainerAutowireAttributeFixture::class, $obj);
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

    public function testUnregisteredAutowiredClassDefaultsToRequestScope(): void
    {
        $c = new Container();
        $v1 = $c->get(ContainerNoDepsFixture::class);
        $this->assertSame($v1, $c->get(ContainerNoDepsFixture::class), 'within one request the instance is reused');

        $c->reset();
        $this->assertNotSame(
            $v1,
            $c->get(ContainerNoDepsFixture::class),
            'an unvetted autowired class must not survive the request boundary as a process singleton',
        );
    }

    /**
     * The captive-dependency leak: before this guard, a singleton constructed once with
     * request 1's user kept handing that user to every later request in the worker, which
     * Container::reset() cannot undo because the reference lives inside the singleton.
     */
    public function testSingletonCannotCaptureRequestScopedDependency(): void
    {
        $c = new Container();
        $c->set(ContainerRequestScopedFixture::class, new ContainerRequestScopedFixture(), Container::SCOPE_REQUEST);
        $c->set(ContainerCaptiveFixture::class, ContainerCaptiveFixture::class, Container::SCOPE_SINGLETON);

        $this->expectException(\Quiote\DI\ContainerException::class);
        $this->expectExceptionMessageMatches('/singleton-scoped but parameter \$req depends on .*which is request-scoped/');
        $c->get(ContainerCaptiveFixture::class);
    }

    public function testSingletonCannotCaptureRequestScopedDependencyViaInjectAttribute(): void
    {
        $c = new Container();
        $c->set('scoped.thing', new ContainerRequestScopedFixture(), Container::SCOPE_REQUEST);
        $c->set(ContainerCaptiveInjectFixture::class, ContainerCaptiveInjectFixture::class, Container::SCOPE_SINGLETON);

        $this->expectException(\Quiote\DI\ContainerException::class);
        $this->expectExceptionMessageMatches("/depends on 'scoped\.thing'/");
        $c->get(ContainerCaptiveInjectFixture::class);
    }

    /**
     * The guard keys off a *declared* request scope, never the inferred default — otherwise
     * every singleton depending on an ordinary unregistered helper would throw.
     */
    public function testSingletonMayDependOnAnOrdinaryUnregisteredClass(): void
    {
        $c = new Container();
        $c->set(ContainerCaptiveFixture::class, ContainerCaptiveFixture::class, Container::SCOPE_SINGLETON);
        $this->assertInstanceOf(ContainerCaptiveFixture::class, $c->get(ContainerCaptiveFixture::class));
    }

    public function testRequestScopedConsumerMayDependOnRequestScopedDependency(): void
    {
        $c = new Container();
        $c->set(ContainerRequestScopedFixture::class, new ContainerRequestScopedFixture(), Container::SCOPE_REQUEST);
        $c->set(ContainerCaptiveFixture::class, ContainerCaptiveFixture::class, Container::SCOPE_REQUEST);
        $this->assertInstanceOf(ContainerCaptiveFixture::class, $c->get(ContainerCaptiveFixture::class));
    }

    /**
     * Actions and views go through make(), are never container-cached, and so may freely
     * depend on request-scoped services.
     */
    public function testMakeMayDependOnRequestScopedDependency(): void
    {
        $c = new Container();
        $c->set(ContainerRequestScopedFixture::class, new ContainerRequestScopedFixture(), Container::SCOPE_REQUEST);
        $this->assertInstanceOf(ContainerCaptiveFixture::class, $c->make(ContainerCaptiveFixture::class));
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
        $this->assertInstanceOf(DateTimeImmutable::class, $obj->clock);
    }

    public function testMakeExtraParamsOverrideByParameterName(): void
    {
        $c = new Container();
        $obj = $c->make(ContainerParamFixture::class, ['name' => 'override_name']);
        $this->assertSame('override_name', $obj->name);
    }

    public function testMakeExtraParamsOverrideByType(): void
    {
        $c = new Container();
        $override = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $obj = $c->make(ContainerMakeFixture::class, [DateTimeImmutable::class => $override]);
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

#[Service]
class ContainerBareServiceAttributeFixture
{
}

#[Service]
class ContainerBareServiceAttributeOnInterfaceFixture implements \Quiote\Service\ServiceInterface
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

class ContainerRequestScopedFixture
{
}

class ContainerCaptiveFixture
{
    public function __construct(public ContainerRequestScopedFixture $req) {}
}

class ContainerCaptiveInjectFixture
{
    public function __construct(
        #[Inject('scoped.thing')] public ContainerRequestScopedFixture $req,
    ) {}
}
