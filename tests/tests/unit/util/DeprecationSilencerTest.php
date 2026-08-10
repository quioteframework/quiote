<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Util\DeprecationSilencer;

/**
 * A deprecation raised from a method called on every request would repeat
 * thousands of times in one worker's log, which is how a message stops being
 * read. This emits each one once per process instead -- and offers an
 * environment switch for the case where you need to see every occurrence to
 * find the caller.
 */
final class DeprecationSilencerTest extends TestCase
{
    /** @var list<string> */
    private array $captured = [];

    private ?string $verboseBackup = null;

    #[Before]
    public function installHandler(): void
    {
        $value = getenv('QUIOTE_DEPRECATION_VERBOSE');
        $this->verboseBackup = $value === false ? null : $value;
        putenv('QUIOTE_DEPRECATION_VERBOSE');

        $this->captured = [];
        set_error_handler(function (int $errno, string $message): bool {
            $this->captured[] = $message;

            return true;
        });
    }

    #[After]
    public function removeHandler(): void
    {
        restore_error_handler();

        if ($this->verboseBackup === null) {
            putenv('QUIOTE_DEPRECATION_VERBOSE');
        } else {
            putenv('QUIOTE_DEPRECATION_VERBOSE=' . $this->verboseBackup);
        }

        // The emitted table is process-static and never pruned, so leave it as found.
        (new ReflectionProperty(DeprecationSilencer::class, 'emitted'))->setValue(null, []);
    }

    private function message(string $suffix): string
    {
        return 'DeprecationSilencerTest message ' . $suffix;
    }

    public function testTheFirstOccurrenceIsEmitted(): void
    {
        DeprecationSilencer::triggerOnce($this->message('first'));

        $this->assertSame([$this->message('first')], $this->captured);
    }

    /** The whole point: a per-request deprecation logs once, not once per request. */
    public function testARepeatedMessageIsEmittedOnlyOnce(): void
    {
        for ($i = 0; $i < 5; $i++) {
            DeprecationSilencer::triggerOnce($this->message('repeated'));
        }

        $this->assertCount(1, $this->captured);
    }

    public function testDistinctMessagesAreEachEmitted(): void
    {
        DeprecationSilencer::triggerOnce($this->message('one'));
        DeprecationSilencer::triggerOnce($this->message('two'));

        $this->assertCount(2, $this->captured);
    }

    /**
     * The de-duplication is per message *and* level, so the same text raised
     * as a notice and as a deprecation is two different reports.
     */
    public function testTheSameTextAtADifferentLevelIsItsOwnMessage(): void
    {
        DeprecationSilencer::triggerOnce($this->message('levelled'), E_USER_DEPRECATED);
        DeprecationSilencer::triggerOnce($this->message('levelled'), E_USER_NOTICE);
        DeprecationSilencer::triggerOnce($this->message('levelled'), E_USER_DEPRECATED);

        $this->assertCount(2, $this->captured);
    }

    /**
     * Silencing is what makes the message readable, but it also hides how
     * often a deprecated path runs -- so the switch turns every occurrence
     * back on for the run where you are hunting the caller.
     */
    public function testTheVerboseSwitchEmitsEveryOccurrence(): void
    {
        putenv('QUIOTE_DEPRECATION_VERBOSE=1');

        for ($i = 0; $i < 3; $i++) {
            DeprecationSilencer::triggerOnce($this->message('verbose'));
        }

        $this->assertCount(3, $this->captured);
    }

    /** Verbose mode does not record anything, so switching it off resumes cleanly. */
    public function testAMessageSeenOnlyInVerboseModeIsStillEmittedOnceAfterwards(): void
    {
        putenv('QUIOTE_DEPRECATION_VERBOSE=1');
        DeprecationSilencer::triggerOnce($this->message('crossover'));
        putenv('QUIOTE_DEPRECATION_VERBOSE');

        DeprecationSilencer::triggerOnce($this->message('crossover'));
        DeprecationSilencer::triggerOnce($this->message('crossover'));

        $this->assertCount(2, $this->captured, 'once in verbose mode, once after');
    }
}
