<?php

use PHPUnit\Framework\TestCase;
use Quiote\Logging\Level;
use Quiote\Logging\LogEvent;
use Quiote\Logging\Sink\AnsiTextStreamSink;
use Quiote\Logging\Sink\EmojiTextStreamSink;
use Quiote\Logging\Sink\FileSink;
use Quiote\Logging\Sink\TextStreamSink;

class SinkTest extends TestCase
{
    private function event(Level $level, string $message = 'hello'): LogEvent
    {
        return new LogEvent(
            timestamp: 1750000000.0,
            level: $level,
            category: 'App.Test',
            messageTemplate: $message,
            properties: [],
            scope: [],
            exception: null,
        );
    }

    /** @return resource */
    private function memoryBuffer()
    {
        $buf = fopen('php://memory', 'r+');
        if ($buf === false) {
            self::fail('Failed to open php://memory for the sink test buffer.');
        }
        return $buf;
    }

    // --- FileSink -----------------------------------------------------------

    public function testFileSinkCreatesMissingParentDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/quiote-filesink-test-' . uniqid();
        $path = $dir . '/nested/app.log';
        $this->assertDirectoryDoesNotExist(dirname($path));

        $sink = new FileSink($path);
        $sink->emit($this->event(Level::Warning, 'disk is on fire'));
        $sink->flush();

        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('WARNING App.Test: disk is on fire', $contents);

        unlink($path);
        rmdir(dirname($path));
        rmdir($dir);
    }

    public function testFileSinkWritesPlainTextWithNoAnsiCodes(): void
    {
        $buf = $this->memoryBuffer();
        $sink = new FileSink('/unused', Level::Debug, [], $buf);
        $sink->emit($this->event(Level::Error, 'boom'));
        rewind($buf);
        $line = stream_get_contents($buf);

        $this->assertStringContainsString('ERROR App.Test: boom', $line);
        $this->assertStringNotContainsString("\033[", $line);
    }

    // --- AnsiTextStreamSink ---------------------------------------------------

    public function testAnsiSinkColorsWarningAndErrorWhenForced(): void
    {
        $buf = $this->memoryBuffer();
        $sink = new AnsiTextStreamSink('php://stderr', Level::Debug, [], $buf, colors: true);

        $sink->emit($this->event(Level::Warning, 'careful'));
        $sink->emit($this->event(Level::Error, 'broken'));
        $sink->emit($this->event(Level::Info, 'fyi'));

        rewind($buf);
        $lines = explode("\n", trim((string) stream_get_contents($buf)));

        $this->assertStringContainsString("\033[33m", $lines[0], 'warning is yellow');
        $this->assertStringContainsString("\033[31m", $lines[1], 'error is red');
        $this->assertStringNotContainsString("\033[", $lines[2], 'info is left plain');
    }

    public function testAnsiSinkDisablesColorsWhenForcedOff(): void
    {
        $buf = $this->memoryBuffer();
        $sink = new AnsiTextStreamSink('php://stderr', Level::Debug, [], $buf, colors: false);
        $sink->emit($this->event(Level::Critical, 'meltdown'));
        rewind($buf);
        $line = stream_get_contents($buf);

        $this->assertStringNotContainsString("\033[", $line);
        $this->assertStringContainsString('CRITICAL App.Test: meltdown', $line);
    }

    public function testAnsiSinkRespectsNoColorEnvVar(): void
    {
        putenv('NO_COLOR=1');
        try {
            $buf = $this->memoryBuffer();
            // No explicit $colors override: NO_COLOR must win even though a
            // resource stream isn't a TTY anyway (belt and suspenders).
            $sink = new AnsiTextStreamSink('php://stderr', Level::Debug, [], $buf);
            $sink->emit($this->event(Level::Error, 'broken'));
            rewind($buf);
            $line = stream_get_contents($buf);
            $this->assertStringNotContainsString("\033[", $line);
        } finally {
            putenv('NO_COLOR');
        }
    }

    // --- EmojiTextStreamSink -------------------------------------------------

    public function testEmojiSinkPrefixesLevelIcon(): void
    {
        $buf = $this->memoryBuffer();
        $sink = new EmojiTextStreamSink('php://stderr', Level::Debug, [], $buf, colors: false);
        $sink->emit($this->event(Level::Error, 'broken'));
        rewind($buf);
        $line = stream_get_contents($buf);

        $this->assertStringStartsWith('‼️', $line);
        $this->assertStringContainsString('ERROR App.Test: broken', $line);
    }
}
