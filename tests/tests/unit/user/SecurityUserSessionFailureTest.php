<?php

declare(strict_types=1);

use Quiote\Context;
use Quiote\DI\Container;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\LogRegistry;
use Quiote\Logging\Sink\SinkInterface;
use Quiote\Session\SessionBagInterface;
use Quiote\Testing\UnitTestCase;
use Quiote\User\SecurityUser;

/** Captures everything at error level or above, so a swallowed failure can be asserted on. */
final class SecurityUserFailureCapturingSink implements SinkInterface
{
    /** @var list<LogEvent> */
    public array $captured = [];

    public function isEnabled(Level $level, string $category): bool
    {
        return $level->value >= Level::Error->value;
    }

    public function emit(LogEvent $event): void
    {
        $this->captured[] = $event;
    }

    public function flush(): void
    {
    }
}

/**
 * What SecurityUser does when the session backend fails under it.
 *
 * Every one of these paths deliberately swallows the throwable, because
 * turning a session outage into a 500 on a successful authentication is the
 * worse outcome. That makes the log the only signal left, so the contract
 * being tested is: the request survives, and the failure is reported at error
 * level saying what was lost.
 */
final class SecurityUserSessionFailureTest extends UnitTestCase
{
    private SecurityUserFailureCapturingSink $sink;

    #[\Override]
    public function setUp(): void
    {
        $this->sink = new SecurityUserFailureCapturingSink();
        Log::addSink($this->sink);
    }

    #[\Override]
    public function tearDown(): void
    {
        LogRegistry::reset();
    }

    /** Its own context per test, so a deliberately broken session bag reaches nothing else. */
    private function userOnFailingSession(string $name, bool $failReads = false): SecurityUser
    {
        $context = Context::getInstance('security-user-failure::' . $name);
        $context->beginRequest();
        $context->getContainer()->set(
            SessionBagInterface::class,
            new FailingSessionBag($failReads),
            Container::SCOPE_REQUEST,
        );

        $user = new SecurityUser();
        $user->initialize($context);

        return $user;
    }

    /** @return list<string> */
    private function capturedMessages(): array
    {
        return array_map(static fn(LogEvent $e): string => $e->renderMessage(), $this->sink->captured);
    }

    private function assertReported(string $needle): void
    {
        $messages = $this->capturedMessages();
        foreach ($messages as $message) {
            if (str_contains($message, $needle)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail(sprintf(
            'no error mentioning "%s" was logged; got: %s',
            $needle,
            $messages === [] ? '(nothing)' : implode(' | ', $messages),
        ));
    }

    /**
     * A login that cannot reach the session is still a successful login for
     * this request -- failing it would turn an outage into a rejected
     * authentication.
     */
    public function testAuthenticationSurvivesASessionThatCannotBeWritten(): void
    {
        $user = $this->userOnFailingSession('login');

        $user->setAuthenticated(true);

        $this->assertTrue($user->isAuthenticated(), 'this request is authenticated regardless');
    }

    /**
     * Both consequences have to be named: the login does not survive the
     * request, and if regenerate() is what failed the pre-login id may still
     * resolve, so fixation may not have been closed.
     */
    public function testAFailedLoginWriteIsReportedWithBothOfItsConsequences(): void
    {
        $this->userOnFailingSession('login-report')->setAuthenticated(true);

        $this->assertReported('will not survive the request');
        $this->assertReported('session fixation may not have been closed');
    }

    /**
     * A logout that did not land leaves a session that still authenticates --
     * the most consequential failure in this class, so it must be reported
     * rather than swallowed silently.
     */
    public function testAFailedLogoutIsReportedAsASessionThatMayStillAuthenticate(): void
    {
        $user = $this->userOnFailingSession('logout');
        $user->setAuthenticated(false);

        $this->assertFalse($user->isAuthenticated());
        $this->assertReported('the session may still authenticate');
    }

    /**
     * Clearing the logged-out session presses on through a key that will not
     * remove: stopping at the first failure would leave more of the session
     * intact than continuing does.
     */
    public function testLogoutKeepsClearingTheRemainingKeysAfterOneFails(): void
    {
        $user = $this->userOnFailingSession('logout-continues');
        $user->addCredential('admin');

        $user->setAuthenticated(true);
        $this->sink->captured = [];
        $user->setAuthenticated(false);

        $bag = $this->bagFor('logout-continues');
        $removals = array_values(array_filter($bag->attempted, static fn(string $a): bool => str_starts_with($a, 'remove:')));

        $this->assertGreaterThan(1, count($removals), 'a single failure must not abandon the rest');
    }

    public function testAFailureToDestroyTheSessionOnLogoutIsReported(): void
    {
        $user = $this->userOnFailingSession('logout-destroy');
        $user->setAuthenticated(true);
        $this->sink->captured = [];

        $user->setAuthenticated(false);

        $this->assertReported('could not destroy the session during logout');
    }

    public function testAFailureToClearASessionKeyOnLogoutNamesTheKey(): void
    {
        $user = $this->userOnFailingSession('logout-key');
        $user->setAuthenticated(true);
        $this->sink->captured = [];

        $user->setAuthenticated(false);

        $this->assertReported('could not clear session key');
    }

    /**
     * Shutdown persists the authentication flag and credentials. When even the
     * fallback write fails, the next request reads a stale value, which is
     * what the report has to say.
     */
    public function testAFailedShutdownWriteIsReportedAsAStaleNextRequest(): void
    {
        $user = $this->userOnFailingSession('shutdown');
        $user->addCredential('admin');

        $user->shutdown();

        $this->assertReported('will read a stale');
    }

    /** A session outage during shutdown must not escape into the response. */
    public function testShutdownDoesNotThrowWhenTheSessionIsUnreachable(): void
    {
        $user = $this->userOnFailingSession('shutdown-quiet');
        $user->addCredential('admin');

        $user->shutdown();

        $this->addToAssertionCount(1);
    }

    /**
     * Reads are not guarded the way writes are: initialize() rehydrates the
     * user straight off the session bag, so a backend that cannot be read
     * fails the request rather than degrading to an empty, unauthenticated
     * user. That is fail-closed -- an outage denies access rather than
     * granting it -- but it is the opposite of what every write path in this
     * class does, so it is pinned here rather than left to be discovered.
     */
    public function testASessionThatCannotBeReadFailsUserInitialization(): void
    {
        $context = Context::getInstance('security-user-failure::read');
        $context->beginRequest();
        $context->getContainer()->set(
            SessionBagInterface::class,
            new FailingSessionBag(failReads: true),
            Container::SCOPE_REQUEST,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('session backend unreachable');

        (new SecurityUser())->initialize($context);
    }

    private function bagFor(string $name): FailingSessionBag
    {
        $bag = Context::getInstance('security-user-failure::' . $name)
            ->getContainer()->get(SessionBagInterface::class);

        $this->assertInstanceOf(FailingSessionBag::class, $bag);

        return $bag;
    }
}
