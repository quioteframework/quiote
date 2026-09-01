<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quiote\Console\Application;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Middleware\TimingMiddleware;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Never actually invoked -- `middleware:list` must not construct any middleware to list it. */
final class MiddlewareListNeverCalledFixtureMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        throw new RuntimeException('middleware:list must not invoke a registered factory just to list it.');
    }
}

/**
 * Exercises `middleware:list` through the CLI harness (CommandTester), against
 * both the sandbox app's real resolved stack and a few `MiddlewareCatalog`
 * states set up directly (disabled override, a registered middleware, a
 * core-stack replacement) to prove the paths that don't otherwise occur for
 * the sandbox app.
 */
final class MiddlewareListCommandTest extends PhpUnitTestCase
{
    protected function tearDown(): void
    {
        MiddlewareCatalog::initialize([]);
        MiddlewareCatalog::reset();
        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        return new CommandTester($application->find('middleware:list'));
    }

    public function testListsCoreMiddlewareInResolvedOrder(): void
    {
        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['middleware']);
        $fqcns = array_column($payload['middleware'], 'fqcn');

        $this->assertContains(RoutingMiddleware::class, $fqcns);
        $this->assertContains(DispatchMiddleware::class, $fqcns);
        $this->assertLessThan(
            array_search(DispatchMiddleware::class, $fqcns, true),
            array_search(RoutingMiddleware::class, $fqcns, true)
        );

        $bySourceFqcn = array_column($payload['middleware'], 'source', 'fqcn');
        $this->assertSame('Core', $bySourceFqcn[RoutingMiddleware::class]);
    }

    public function testTableOutputShowsTheSourceColumn(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('RoutingMiddleware', $display);
        $this->assertStringContainsString('Core', $display);
    }

    public function testDisabledMiddlewareIsExcludedFromTheStackAndReportedSeparately(): void
    {
        MiddlewareCatalog::initialize([TimingMiddleware::class => false]);

        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['middleware']);
        self::assertIsArray($payload['disabled']);
        $fqcns = array_column($payload['middleware'], 'fqcn');

        $this->assertNotContains(TimingMiddleware::class, $fqcns);
        $this->assertContains(TimingMiddleware::class, $payload['disabled']);
    }

    public function testDisabledMiddlewareIsNotedInTableOutput(): void
    {
        MiddlewareCatalog::initialize([TimingMiddleware::class => false]);

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertStringContainsString('Disabled, excluded from the pipeline', $tester->getDisplay());
        $this->assertStringContainsString(TimingMiddleware::class, $tester->getDisplay());
    }

    public function testRegisteredMiddlewareIsSplicedInAtItsHintWithoutBeingConstructed(): void
    {
        MiddlewareCatalog::register(
            MiddlewareListNeverCalledFixtureMiddleware::class,
            static fn() => throw new RuntimeException('never called'),
            after: RoutingMiddleware::class,
        );

        $tester = $this->tester();
        $exitCode = $tester->execute(['--json' => true]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['middleware']);
        $fqcns = array_column($payload['middleware'], 'fqcn');
        $bySourceFqcn = array_column($payload['middleware'], 'source', 'fqcn');

        $position = array_search(MiddlewareListNeverCalledFixtureMiddleware::class, $fqcns, true);
        $this->assertNotFalse($position);
        $this->assertSame(
            array_search(RoutingMiddleware::class, $fqcns, true) + 1,
            $position
        );
        $this->assertSame('Registered', $bySourceFqcn[MiddlewareListNeverCalledFixtureMiddleware::class]);
    }

    public function testCoreStackOverrideIsReportedRatherThanListed(): void
    {
        MiddlewareCatalog::replaceCoreStack(
            static fn() => [],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT
        );

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertIsString($display);
        $this->assertStringContainsString('replaced via MiddlewareCatalog::replaceCoreStack()', $display);
    }

    public function testCoreStackOverrideJsonOutputSignalsTheOverride(): void
    {
        MiddlewareCatalog::replaceCoreStack(
            static fn() => [],
            MiddlewareCatalog::REPLACE_CORE_STACK_ACKNOWLEDGEMENT
        );

        $tester = $this->tester();
        $tester->execute(['--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $this->assertTrue($payload['overridden']);
    }
}
