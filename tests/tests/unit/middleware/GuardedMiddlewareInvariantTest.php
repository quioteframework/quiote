<?php

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Exception\ConfigurationException;
use Quiote\Middleware\Config\MiddlewareConfigRegistry;
use Quiote\Middleware\DispatchMiddleware;
use Quiote\Middleware\MiddlewareCatalog;
use Quiote\Middleware\MiddlewarePipeline;
use Quiote\Middleware\RoutingMiddleware;
use Quiote\Middleware\SecurityMiddleware;
use Quiote\Middleware\SessionMiddleware;
use Quiote\Security\Csrf\Middleware\CsrfValidationMiddleware;

/**
 * Invariants over the *set* of guarded middleware and the security-relevant
 * *relations* in the built pipeline, as opposed to the exact sequence pinned by
 * MiddlewareAttributeOrderingTest.
 *
 * The distinction matters. A golden-sequence test fails on any reordering,
 * benign or not, and when it fails it says "the list changed" -- not "a security
 * guarantee broke". These assertions survive benign reordering and fail with the
 * reason attached, and they are the tests that would have caught two of the
 * defects this file exists because of:
 *
 *  - The framework-override guard claimed, in its own error message, to cover
 *    "error handling, sessions, CSRF, security, routing". It did not cover CSRF:
 *    the guard consulted a list of classes the pipeline builds factories for, and
 *    the CSRF middleware ship in their own package. The claim lived in a string,
 *    with no test tying the list to the guard.
 *  - An unresolvable `before:`/`after:` reference was silently dropped, so
 *    "CSRF validation runs before dispatch" could degrade from a guarantee into an
 *    accident of the current priorities with only a log line to say so.
 */
class GuardedMiddlewareInvariantTest extends TestCase
{
    /** @var mixed */
    private $originalOverrideSetting;

    protected function setUp(): void
    {
        MiddlewareCatalog::initialize([]);
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
        $this->originalOverrideSetting = Config::has(MiddlewareConfigRegistry::OVERRIDE_SETTING)
            ? Config::get(MiddlewareConfigRegistry::OVERRIDE_SETTING)
            : null;
        // This test builds a pipeline via Context::getInstance() without going
        // through Quiote::bootstrap(), so the CSRF plugin has to be registered by
        // hand -- same as MiddlewareAttributeOrderingTest.
        (new \Quiote\Security\Csrf\CsrfPlugin())->register(new \Quiote\Plugin\PluginRegistrar('quiote/csrf'));
    }

    protected function tearDown(): void
    {
        MiddlewareCatalog::reset();
        MiddlewareConfigRegistry::reset();
        if ($this->originalOverrideSetting === null) {
            Config::remove(MiddlewareConfigRegistry::OVERRIDE_SETTING);
        } else {
            Config::set(MiddlewareConfigRegistry::OVERRIDE_SETTING, $this->originalOverrideSetting);
        }
    }

    // --- the guarded set and the guard cannot drift apart ---

    /** @return array<string, array{string}> */
    public static function guardedClasses(): array
    {
        $cases = [];
        foreach (MiddlewarePipeline::guardedMiddlewareClasses() as $fqcn) {
            $cases[$fqcn] = [$fqcn];
        }

        return $cases;
    }

    #[DataProvider('guardedClasses')]
    public function testEveryGuardedClassIsRefusedReordering(string $fqcn): void
    {
        $this->expectException(ConfigurationException::class);

        MiddlewareConfigRegistry::contribute([[
            'class' => $fqcn,
            'phase' => 'finalize',
            'priority' => null,
            'before' => null,
            'after' => null,
            'enabled' => null,
            'override_framework' => false,
        ]], 'test://middleware.xml');
    }

    #[DataProvider('guardedClasses')]
    public function testEveryGuardedClassIsRefusedDisabling(string $fqcn): void
    {
        $this->expectException(ConfigurationException::class);

        MiddlewareConfigRegistry::contribute([[
            'class' => $fqcn,
            'phase' => null,
            'priority' => null,
            'before' => null,
            'after' => null,
            'enabled' => false,
            'override_framework' => false,
        ]], 'test://middleware.xml');
    }

    public function testTheCsrfMiddlewareAreGuarded(): void
    {
        // Named explicitly rather than left to the data provider: the guard's error
        // message promises CSRF coverage, and this is the assertion behind that
        // promise. If the CSRF middleware ever leave the guarded set, this fails
        // with the reason rather than the provider silently covering one fewer class.
        $guarded = MiddlewarePipeline::guardedMiddlewareClasses();

        $this->assertContains(CsrfValidationMiddleware::class, $guarded);
        $this->assertContains(\Quiote\Security\Csrf\Middleware\CsrfInjectionMiddleware::class, $guarded);
    }

    public function testGuardedClassesCanStillBeOverriddenWithBothAuthorizations(): void
    {
        // Positive control: without this, the tests above would pass just as well if
        // the guard refused everything unconditionally, which would be a different bug.
        Config::set(MiddlewareConfigRegistry::OVERRIDE_SETTING, true);

        MiddlewareConfigRegistry::contribute([[
            'class' => CsrfValidationMiddleware::class,
            'phase' => 'finalize',
            'priority' => null,
            'before' => null,
            'after' => null,
            'enabled' => null,
            'override_framework' => true,
        ]], 'test://middleware.xml');

        $this->assertCount(1, MiddlewareConfigRegistry::all());
    }

    public function testAnUnguardedClassIsNotRefused(): void
    {
        MiddlewareConfigRegistry::contribute([[
            'class' => AttributeOrderingFixtureMiddleware::class,
            'phase' => 'finalize',
            'priority' => null,
            'before' => null,
            'after' => null,
            'enabled' => false,
            'override_framework' => false,
        ]], 'test://middleware.xml');

        $this->assertCount(1, MiddlewareConfigRegistry::all());
    }

    // --- security-relevant ordering, as relations rather than a sequence ---

    /** @return list<string> */
    private function order(): array
    {
        $pipeline = new MiddlewarePipeline(Context::getInstance());
        try {
            $pipeline->handle(new ServerRequest('GET', 'http://localhost/test'));
        } catch (\Throwable) {
            // debugStack is populated during build, before the stack runs.
        }

        return $pipeline->debugStack();
    }

    private function assertRunsBefore(string $earlier, string $later, string $why): void
    {
        $order = $this->order();
        $earlierAt = array_search($earlier, $order, true);
        $laterAt = array_search($later, $order, true);

        $this->assertNotFalse($earlierAt, $earlier . ' must be in the built pipeline');
        $this->assertNotFalse($laterAt, $later . ' must be in the built pipeline');
        $this->assertLessThan($laterAt, $earlierAt, $why);
    }

    public function testCsrfValidationRunsBeforeDispatch(): void
    {
        $this->assertRunsBefore(
            CsrfValidationMiddleware::class,
            DispatchMiddleware::class,
            'CSRF validation after dispatch would validate a token for an action that already ran, '
            . 'which is not validation at all. This relation is what the middleware\'s '
            . '`before: DispatchMiddleware` constraint exists to guarantee.'
        );
    }

    public function testSecurityRunsBeforeDispatch(): void
    {
        $this->assertRunsBefore(
            SecurityMiddleware::class,
            DispatchMiddleware::class,
            'authorization after dispatch would decide access to an action that already executed'
        );
    }

    public function testCsrfValidationRunsAfterRouting(): void
    {
        $this->assertRunsBefore(
            RoutingMiddleware::class,
            CsrfValidationMiddleware::class,
            'the per-route `_csrf` opt-out is only knowable once routing has resolved route_params; '
            . 'running earlier would silently ignore it'
        );
    }

    public function testSessionRunsBeforeCsrfValidation(): void
    {
        $this->assertRunsBefore(
            SessionMiddleware::class,
            CsrfValidationMiddleware::class,
            'the CSRF token is stored in the session, so validation cannot precede session startup'
        );
    }

    public function testEveryGuardedClassActuallyParticipatesInThePipeline(): void
    {
        // Keeps the guarded list honest in the other direction: a list naming a class
        // that never runs would give false assurance, since guarding it protects
        // nothing.
        $order = $this->order();

        foreach (MiddlewarePipeline::guardedMiddlewareClasses() as $fqcn) {
            $this->assertContains(
                $fqcn,
                $order,
                $fqcn . ' is in the guarded set but does not appear in the built pipeline; guarding a '
                . 'middleware that never runs is false assurance'
            );
        }
    }
}
