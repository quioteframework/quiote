<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Quiote\DevOverlay\Report\ExceptionReportBuilder;
use Quiote\Error\HandledException;
use Quiote\Support\Clock\FrozenClock;

final class ExceptionReportBuilderTest extends TestCase
{
    private function builder(int $maxFrames = 60, int $maxSourceFrames = 12, int $maxChain = 10): ExceptionReportBuilder
    {
        return new ExceptionReportBuilder(
            FrozenClock::fromDateTime(new DateTimeImmutable('2026-10-30T12:00:00+00:00')),
            $maxFrames,
            $maxSourceFrames,
            $maxChain,
        );
    }

    private static function thrownHere(string $message, ?Throwable $previous = null): RuntimeException
    {
        try {
            throw new RuntimeException($message, 42, $previous);
        } catch (RuntimeException $e) {
            return $e;
        }
    }

    public function testCarriesTheRequestStatusIdAndTime(): void
    {
        $report = $this->builder()->build(
            str_repeat('a', 32),
            self::thrownHere('boom'),
            new ServerRequest('POST', 'https://app.test/orders?page=2'),
            500,
        );

        $this->assertSame(str_repeat('a', 32), $report['id']);
        $this->assertSame('2026-10-30T12:00:00+00:00', $report['time']);
        $this->assertSame(500, $report['status']);
        $this->assertSame(['method' => 'POST', 'uri' => 'https://app.test/orders?page=2'], $report['request']);
    }

    public function testDescribesTheThrowSiteWithASourceExcerpt(): void
    {
        $exception = self::thrownHere('boom');

        $entry = $this->builder()->build('id', $exception, new ServerRequest('GET', '/'), 500)['exceptions'][0];

        $this->assertSame(RuntimeException::class, $entry['class']);
        $this->assertSame('boom', $entry['message']);
        $this->assertSame(42, $entry['code']);
        $this->assertSame(__FILE__, $entry['file']);
        $this->assertSame($exception->getLine(), $entry['line']);
        $this->assertNotNull($entry['source']);
        $this->assertSame($exception->getLine(), $entry['source']['highlight']);
        $highlighted = $entry['source']['lines'][$entry['source']['highlight'] - $entry['source']['start']];
        $this->assertStringContainsString('throw new RuntimeException($message, 42, $previous);', $highlighted);
    }

    public function testFollowsThePreviousChain(): void
    {
        $exception = self::thrownHere('outer', new LogicException('inner'));

        $entries = $this->builder()->build('id', $exception, new ServerRequest('GET', '/'), 500)['exceptions'];

        $this->assertCount(2, $entries);
        $this->assertSame(LogicException::class, $entries[1]['class']);
        $this->assertSame('inner', $entries[1]['message']);
    }

    public function testTheChainIsCapped(): void
    {
        $exception = new RuntimeException('3', 0, new RuntimeException('2', 0, new RuntimeException('1')));

        $entries = $this->builder(maxChain: 2)->build('id', $exception, new ServerRequest('GET', '/'), 500)['exceptions'];

        $this->assertSame(['3', '2'], array_column($entries, 'message'));
    }

    public function testFramesAreLabelledWithTheFunctionTheyAreIn(): void
    {
        $frames = $this->builder()->build('id', self::thrownHere('boom'), new ServerRequest('GET', '/'), 500)['exceptions'][0]['frames'];

        // The first frame is the throw site, inside thrownHere(); the second is the call to
        // thrownHere(), inside this test method.
        $this->assertSame(self::class . '::thrownHere()', $frames[0]['call']);
        $this->assertSame(__FILE__, $frames[1]['file']);
        $this->assertSame(self::class . '->' . __FUNCTION__ . '()', $frames[1]['call']);
    }

    public function testVendorFramesAreFlaggedAndCarryNoSource(): void
    {
        $frames = $this->builder()->build('id', self::thrownHere('boom'), new ServerRequest('GET', '/'), 500)['exceptions'][0]['frames'];

        $vendor = array_values(array_filter($frames, static fn(array $frame): bool => $frame['vendor']));
        $this->assertNotSame([], $vendor, 'PHPUnit itself runs from vendor/, so some frame must be flagged.');
        foreach ($vendor as $frame) {
            $this->assertNull($frame['source']);
        }
        $this->assertFalse($frames[0]['vendor']);
    }

    public function testFrameAndSourceCountsAreCapped(): void
    {
        $frames = $this->builder(maxFrames: 3, maxSourceFrames: 1)
            ->build('id', self::thrownHere('boom'), new ServerRequest('GET', '/'), 500)['exceptions'][0]['frames'];

        $this->assertCount(3, $frames);
        $this->assertCount(1, array_filter($frames, static fn(array $frame): bool => $frame['source'] !== null));
    }

    public function testAnUnreadableThrowSiteHasNoExcerpt(): void
    {
        $exception = new class ('eval\'d') extends RuntimeException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->file = '/nonexistent/path/Generated.php';
                $this->line = 10;
            }
        };

        $entry = $this->builder()->build('id', $exception, new ServerRequest('GET', '/'), 500)['exceptions'][0];

        $this->assertSame('/nonexistent/path/Generated.php', $entry['file']);
        $this->assertNull($entry['source']);
    }

    public function testTraceArgumentsAreNeverIncluded(): void
    {
        // Generated, not written out: the excerpts show this file's own source, so a literal
        // secret would appear in the report through them rather than through the arguments.
        $secret = bin2hex(random_bytes(8));
        $exception = (static function (string $secret): RuntimeException {
            try {
                throw new RuntimeException('boom');
            } catch (RuntimeException $e) {
                return $e;
            }
        })($secret);

        $json = json_encode($this->builder()->build('id', $exception, new ServerRequest('GET', '/'), 500), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($secret, $json);
    }

    public function testARequestThatEndedNormallyHasNoCaughtChain(): void
    {
        $report = $this->builder()->build('id', null, new ServerRequest('GET', '/'), 200);

        $this->assertSame([], $report['exceptions']);
        $this->assertSame([], $report['handled']);
    }

    public function testDescribesEachHandledExceptionWithItsNoteAndChain(): void
    {
        $handled = [
            new HandledException(self::thrownHere('outer', new LogicException('inner')), 'answered 400'),
            new HandledException(self::thrownHere('second')),
        ];

        $report = $this->builder()->build('id', null, new ServerRequest('GET', '/'), 400, $handled);

        $this->assertCount(2, $report['handled']);
        $this->assertSame('answered 400', $report['handled'][0]['note']);
        $this->assertSame(['outer', 'inner'], array_column($report['handled'][0]['exceptions'], 'message'));
        $this->assertNull($report['handled'][1]['note']);
        $this->assertSame(__FILE__, $report['handled'][1]['exceptions'][0]['file']);
        $this->assertNotNull($report['handled'][1]['exceptions'][0]['source']);
    }

    public function testAHandledChainIsCappedLikeTheCaughtOne(): void
    {
        $exception = new RuntimeException('3', 0, new RuntimeException('2', 0, new RuntimeException('1')));

        $report = $this->builder(maxChain: 2)->build('id', null, new ServerRequest('GET', '/'), 400, [new HandledException($exception)]);

        $this->assertSame(['3', '2'], array_column($report['handled'][0]['exceptions'], 'message'));
    }
}
