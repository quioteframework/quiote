<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Context;
use Quiote\DI\Container;
use Quiote\DI\ContainerException;
use Quiote\Error\ErrorReporting;
use Quiote\Error\HandledException;
use Quiote\Error\HandledExceptions;
use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\Sink\SinkInterface;
use Quiote\Request\RequestState;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;

#[IsolationEnvironment('testing')]
final class ErrorReportingTest extends PhpUnitTestCase
{
    private ?bool $developerExceptions = null;

    #[Before]
    public function rememberDeveloperExceptions(): void
    {
        $this->developerExceptions = Config::has('core.developer_exceptions') ? Config::getBool('core.developer_exceptions') : null;
        Config::set('core.developer_exceptions', true, true, false);
    }

    #[After]
    public function restoreDeveloperExceptions(): void
    {
        Config::remove('core.developer_exceptions');
        if ($this->developerExceptions !== null) {
            Config::set('core.developer_exceptions', $this->developerExceptions, true, false);
        }
        Log::reset();
    }

    private static function reportingInto(HandledExceptions $list): ErrorReporting
    {
        return new ErrorReporting(static fn(): HandledExceptions => $list);
    }

    public function testRecordsTheExceptionWithItsNote(): void
    {
        $list = new HandledExceptions();
        $exception = new RuntimeException('AuthService unreachable');

        self::reportingInto($list)->handled($exception, 'answered 400');

        $this->assertEquals([new HandledException($exception, 'answered 400')], $list->all());
    }

    public function testKeepsEveryReportInOrder(): void
    {
        $list = new HandledExceptions();
        $reporting = self::reportingInto($list);
        $first = new RuntimeException('first');
        $second = new LogicException('second');

        $reporting->handled($first);
        $reporting->handled($second, 'fell back to the cache');

        $this->assertSame([$first, $second], array_map(static fn(HandledException $entry): Throwable => $entry->exception, $list->all()));
        $this->assertSame([null, 'fell back to the cache'], array_map(static fn(HandledException $entry): ?string => $entry->note, $list->all()));
    }

    public function testDoesNothingWithoutDeveloperExceptions(): void
    {
        Config::set('core.developer_exceptions', false, true, false);
        $resolved = false;
        $reporting = new ErrorReporting(static function () use (&$resolved): HandledExceptions {
            $resolved = true;

            return new HandledExceptions();
        });

        $reporting->handled(new RuntimeException('boom'));

        $this->assertFalse($resolved, 'the request\'s list is not even resolved');
    }

    public function testDropsReportsBeyondTheCapAndSaysSoOnce(): void
    {
        $sink = new class implements SinkInterface {
            /** @var list<LogEvent> */
            public array $captured = [];

            public function isEnabled(Level $level, string $category): bool
            {
                return true;
            }

            public function emit(LogEvent $event): void
            {
                $this->captured[] = $event;
            }

            public function flush(): void
            {
            }
        };
        Log::addSink($sink);
        $list = new HandledExceptions();

        for ($i = 0; $i < HandledExceptions::MAX + 3; $i++) {
            $list->add(new HandledException(new RuntimeException((string) $i)));
        }

        $this->assertCount(HandledExceptions::MAX, $list->all());
        $this->assertSame('0', $list->all()[0]->exception->getMessage(), 'the earliest reports are the ones kept');
        $warnings = array_values(array_filter($sink->captured, static fn(LogEvent $event): bool => $event->level === Level::Warning));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('More than ' . HandledExceptions::MAX . ' handled exceptions', $warnings[0]->renderMessage());
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTheContainerWiresASingletonAccessorOverARequestScopedList(): void
    {
        $container = Context::getInstance()->getContainer();

        $reporting = $container->get(ErrorReporting::class);
        $this->assertInstanceOf(ErrorReporting::class, $reporting);
        $this->assertSame($reporting, $container->get('errorReporting'));

        $exception = new RuntimeException('handled');
        $reporting->handled($exception);
        $list = $container->get(HandledExceptions::class);
        $this->assertInstanceOf(HandledExceptions::class, $list);
        $this->assertSame($exception, $list->all()[0]->exception);
    }

    /** What a persistent worker relies on: the list does not survive the request boundary. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTheListStartsEmptyAfterTheRequestBoundary(): void
    {
        $context = Context::getInstance();
        $reporting = $context->getContainer()->get(ErrorReporting::class);
        $reporting->handled(new RuntimeException('request one'));

        $context->reset();

        $this->assertSame([], $context->getContainer()->get(HandledExceptions::class)->all());
        $reporting->handled(new RuntimeException('request two'));
        $this->assertCount(1, $context->getContainer()->get(HandledExceptions::class)->all());
    }

    /** A handled exception is not the request's outcome, so it never reaches the `Throwable` attribute. */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testDoesNotMarkTheRequestAsFailed(): void
    {
        $container = Context::getInstance()->getContainer();

        $container->get(ErrorReporting::class)->handled(new RuntimeException('handled'));

        $this->assertNull($container->get(RequestState::class)->current()->getAttribute(Throwable::class));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testASingletonCapturingTheListIsPointedAtTheAccessor(): void
    {
        $container = Context::getInstance()->getContainer();
        $container->set(SingletonCapturingHandledExceptions::class, SingletonCapturingHandledExceptions::class, Container::SCOPE_SINGLETON);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Inject ' . ErrorReporting::class . ' instead');

        $container->get(SingletonCapturingHandledExceptions::class);
    }
}

final class SingletonCapturingHandledExceptions
{
    public function __construct(public readonly HandledExceptions $handled)
    {
    }
}
