<?php

use Quiote\Context;
use Quiote\Middleware\CoreMiddlewareRegistry;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;

/**
 * The registry is the single declaration of the middleware core ships. What core builds and
 * what config is forbidden from silently disabling must be answers to the same question, since
 * a middleware present in one list but missing from the other is either unconstructable or
 * unguarded.
 */
#[IsolationEnvironment('testing')]
class CoreMiddlewareRegistryTest extends PhpUnitTestCase
{
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testEveryDeclaredCoreMiddlewareHasAFactory(): void
    {
        $factories = CoreMiddlewareRegistry::factories(Context::getInstance());

        $this->assertSame(CoreMiddlewareRegistry::CORE, array_keys($factories));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testEveryFactoryBuildsTheMiddlewareItIsKeyedBy(): void
    {
        $context = Context::getInstance();

        foreach (CoreMiddlewareRegistry::factories($context) as $fqcn => $factory) {
            $middleware = $factory($context);

            $this->assertInstanceOf(\Psr\Http\Server\MiddlewareInterface::class, $middleware);
            $this->assertInstanceOf($fqcn, $middleware, "factory for $fqcn built " . $middleware::class);
        }
    }

    public function testGuardedSetCoversCoreAndPluginProvidedMiddleware(): void
    {
        $guarded = CoreMiddlewareRegistry::guardedClasses();

        foreach (CoreMiddlewareRegistry::CORE as $fqcn) {
            $this->assertContains($fqcn, $guarded, "$fqcn must be guarded");
        }
        foreach (CoreMiddlewareRegistry::pluginProvidedClasses() as $fqcn) {
            $this->assertContains($fqcn, $guarded, "$fqcn must be guarded");
        }
        $this->assertCount(
            count(CoreMiddlewareRegistry::CORE) + count(CoreMiddlewareRegistry::pluginProvidedClasses()),
            $guarded
        );
    }

    /**
     * The CSRF middleware are delivered by a plugin core enables by default, so they are
     * guarded without core building them. Losing that coverage is how a single config entry
     * was once able to switch CSRF validation off silently.
     */
    public function testCsrfMiddlewareAreGuardedWithoutBeingBuiltByCore(): void
    {
        $guarded = CoreMiddlewareRegistry::guardedClasses();

        foreach ([
            'Quiote\\Security\\Csrf\\Middleware\\CsrfValidationMiddleware',
            'Quiote\\Security\\Csrf\\Middleware\\CsrfInjectionMiddleware',
        ] as $fqcn) {
            $this->assertContains($fqcn, $guarded);
            $this->assertNotContains($fqcn, CoreMiddlewareRegistry::CORE);
        }
    }

    /**
     * Ordering belongs to each class's own #[Middleware] attribute, not to the registry, so a
     * middleware declares its placement next to its implementation.
     */
    public function testRegistryDeclaresNoOrdering(): void
    {
        $source = file_get_contents((new ReflectionClass(CoreMiddlewareRegistry::class))->getFileName() ?: '');
        $this->assertIsString($source);

        foreach (['before:', 'after:', 'priority:', "'phase'"] as $orderingHint) {
            $this->assertStringNotContainsString($orderingHint, $source);
        }
    }

    /**
     * The pipeline's own accessors stay as a compatibility surface and must keep answering
     * from the registry rather than a copy of their own.
     */
    public function testPipelineAccessorsDelegateToTheRegistry(): void
    {
        $this->assertSame(CoreMiddlewareRegistry::CORE, MiddlewarePipeline::coreMiddlewareClasses());
        $this->assertSame(
            CoreMiddlewareRegistry::pluginProvidedClasses(),
            MiddlewarePipeline::protectedPackageMiddlewareClasses()
        );
        $this->assertSame(CoreMiddlewareRegistry::guardedClasses(), MiddlewarePipeline::guardedMiddlewareClasses());
    }
}
