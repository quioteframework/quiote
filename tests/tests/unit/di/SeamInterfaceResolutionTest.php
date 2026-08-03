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

        $this->assertSame($ctx->getController(), $ctx->getContainer()->get(ControllerInterface::class));
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
     * The interface surface is deliberately the reading surface: a consumer given the contract
     * can reach the framework's other pieces, but not drive the context's own lifecycle.
     */
    public function testContextInterfaceExposesAccessorsButNotLifecycle(): void
    {
        $reflection = new ReflectionClass(ContextInterface::class);
        $methods = array_map(static fn(ReflectionMethod $m): string => $m->getName(), $reflection->getMethods());

        foreach (['getRequest', 'getUser', 'getRouting', 'getController', 'getService', 'getModel'] as $expected) {
            $this->assertContains($expected, $methods);
        }
        foreach (['initialize', 'shutdown', 'reset', 'handle', 'setRequest', 'setFactoryInfo'] as $excluded) {
            $this->assertNotContains($excluded, $methods);
        }
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
}

class SeamInterfaceConsumer
{
    public function __construct(public readonly ContextInterface $context) {}
}
