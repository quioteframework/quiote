<?php

use Quiote\Context;
use Quiote\ContextInterface;
use Quiote\Controller\Controller;
use Quiote\Controller\ControllerInterface;
use Quiote\Response\WebResponse;
use Quiote\Response\WebResponseInterface;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Validator\Validator;
use Quiote\Validator\ValidatorInterface;

/**
 * The core collaborators are reachable through their contracts, so framework and application
 * code can depend on the interface rather than the concrete class, and the container resolves
 * the interface to the request's real instance.
 */
#[IsolationEnvironment('testing')]
class SeamInterfaceResolutionTest extends PhpUnitTestCase
{
    public function testConcreteClassesImplementTheirSeamInterface(): void
    {
        $pairs = [
            Context::class => ContextInterface::class,
            Controller::class => ControllerInterface::class,
            WebResponse::class => WebResponseInterface::class,
            Validator::class => ValidatorInterface::class,
        ];

        foreach ($pairs as $concrete => $contract) {
            $this->assertContains(
                $contract,
                (new ReflectionClass($concrete))->getInterfaceNames(),
                "$concrete must implement $contract"
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testContextInterfaceResolvesToTheRequestsContext(): void
    {
        $ctx = Context::getInstance();

        $this->assertSame($ctx, $ctx->getContainer()->get(ContextInterface::class));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testControllerInterfaceResolvesToTheRequestsController(): void
    {
        $ctx = Context::getInstance();

        $this->assertSame($ctx->getContainer()->get(\Quiote\Controller\Controller::class), $ctx->getContainer()->get(ControllerInterface::class));
    }

    /**
     * A service may declare the contract and be autowired the live collaborator.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testAServiceCanConstructorInjectTheContextContract(): void
    {
        $ctx = Context::getInstance();

        $service = $ctx->getContainer()->make(SeamInterfaceConsumer::class);
        $this->assertInstanceOf(SeamInterfaceConsumer::class, $service);

        $this->assertSame($ctx, $service->context);
    }

    /**
     * The contract is which profile this is and how to reach its services -- nothing else. The
     * accessors are gone on purpose: a class that needs the routing or the user declares that in its
     * constructor, where the container can see it, rather than reaching through the context, which
     * works from anywhere and so hides every real dependency.
     */
    public function testContextInterfaceIsTheProfileAndItsContainerOnly(): void
    {
        $reflection = new ReflectionClass(ContextInterface::class);
        $methods = array_map(static fn(ReflectionMethod $m): string => $m->getName(), $reflection->getMethods());
        sort($methods);

        $this->assertSame(['getContainer', 'getName'], $methods);
    }

    public function testControllerInterfaceExposesDispatchSurfaceButNotLifecycle(): void
    {
        $reflection = new ReflectionClass(ControllerInterface::class);
        $methods = array_map(static fn(ReflectionMethod $m): string => $m->getName(), $reflection->getMethods());

        foreach (['createActionInstance', 'createViewInstance', 'getGlobalResponse', 'getContext'] as $expected) {
            $this->assertContains($expected, $methods);
        }
        foreach (['startup', 'shutdown', 'reset', 'initialize'] as $excluded) {
            $this->assertNotContains($excluded, $methods);
        }
    }

    /**
     * Narrow to the contract, so the assertions below exercise only what it declares.
     */
    private function asContract(WebResponseInterface $response): WebResponseInterface
    {
        return $response;
    }

    public function testWebResponseSatisfiesTheContractThroughTheInterface(): void
    {
        $contract = $this->asContract(new WebResponse());
        $contract->setContent('body');
        $contract->setHttpStatusCode(201);
        $contract->setHttpHeader('X-Contract', 'yes');

        $this->assertSame('body', $contract->getContent());
        $this->assertEquals('201', $contract->getHttpStatusCode());
        $this->assertSame(['yes'], $contract->getHttpHeader('X-Contract'));
        $this->assertSame(201, $contract->toPsrResponse()->getStatusCode());
    }

    /**
     * Base classes are seam contracts too, and this is the case that made it matter.
     *
     * An application configures a `request` subclass, so binding only the concrete class left
     * `WebRequest` -- the natural type-hint -- unregistered. The container happily autowired a
     * brand-new one for it, so a consumer asking for the request got an empty request carrying
     * none of this request's parameters, headers or body, and nothing said so.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function dataRequestScopedBaseContracts(): array
    {
        return [
            'request base class' => [\Quiote\Request\WebRequest::class],
            'user base class' => [\Quiote\User\User::class],
        ];
    }

    /** @param class-string $contract */
    #[\PHPUnit\Framework\Attributes\DataProvider('dataRequestScopedBaseContracts')]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testABaseContractResolvesToTheRequestsInstanceNotAFreshOne(string $contract): void
    {
        $ctx = Context::getInstance();
        // Force both components to exist, since they are built lazily.
        $ctx->getRequest();
        $ctx->getContainer()->get(\Quiote\User\User::class);

        $resolved = $ctx->getContainer()->get($contract);

        $this->assertInstanceOf($contract, $resolved);
        $this->assertTrue(
            $resolved === $ctx->getRequest() || $resolved === $ctx->getContainer()->get(\Quiote\User\User::class),
            "$contract must resolve to the request's own instance, not a freshly autowired one",
        );
    }

    /**
     * The configured subclass and its base must be the same object, not two.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTheBaseContractAndTheConcreteClassResolveToOneInstance(): void
    {
        $ctx = Context::getInstance();
        $request = $ctx->getRequest();
        $container = $ctx->getContainer();

        $this->assertSame($request, $container->get($request::class));
        $this->assertSame($request, $container->get(\Quiote\Request\WebRequest::class));
        $this->assertSame($request, $container->get('request'));
    }

    /**
     * Binding the base classes is also what lets the captive-dependency guard see the natural
     * type-hint as request-scoped, instead of letting a singleton capture one.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTheRequestBaseContractIsRequestScopedSoASingletonCannotCaptureIt(): void
    {
        $ctx = Context::getInstance();
        $ctx->getRequest();
        $container = $ctx->getContainer();
        $container->set(
            SeamRequestCapturingSingleton::class,
            SeamRequestCapturingSingleton::class,
            \Quiote\DI\Container::SCOPE_SINGLETON,
        );

        $this->expectException(\Quiote\DI\ContainerException::class);
        $container->get(SeamRequestCapturingSingleton::class);
    }
}

class SeamRequestCapturingSingleton
{
    public function __construct(public readonly \Quiote\Request\WebRequest $request) {}
}

class SeamInterfaceConsumer
{
    public function __construct(public readonly ContextInterface $context) {}
}
