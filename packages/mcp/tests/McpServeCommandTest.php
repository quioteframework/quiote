<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Mcp\Console\McpServeCommand;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `mcp:serve` is opt-in-gated on `mcp.enabled` and resolves a `--context`
 * option (falling back to `core.default_context`, then "web") before
 * touching the stdio transport; this exercises the gate and the context
 * resolution, not the transport's read loop itself, which is exercised
 * end-to-end by McpServerTest instead (via InMemoryTransport, driving
 * McpServer directly).
 *
 * Any test below that reaches a resolved container falls through to the
 * command's real `runStdio()` call. That call defaults to the process's
 * real STDIN/STDOUT, and `Mcp\Server::run()` unconditionally `fclose()`s
 * whatever streams the transport holds once `listen()` returns (in a
 * `finally` block) -- so letting it touch the real streams here would
 * close this PHPUnit process's actual stdio out from under every later
 * test (including PHPUnit's own event logger). `tester()` below hands
 * those tests throwaway in-memory streams instead via
 * `McpServeCommand`'s constructor override, so the real process stdio is
 * never touched.
 */
final class McpServeCommandTest extends PhpUnitTestCase
{
    #[Before]
    #[After]
    public function resetMcpEnabled(): void
    {
        Config::remove('mcp.enabled');
    }

    /**
     * @param resource|null $stdioInput
     * @param resource|null $stdioOutput
     */
    private function tester(mixed $stdioInput = null, mixed $stdioOutput = null): CommandTester
    {
        return new CommandTester(new McpServeCommand($stdioInput, $stdioOutput));
    }

    /**
     * A throwaway in-memory stream standing in for the process's real STDIN/STDOUT.
     * @return resource
     */
    private function fakeStdioStream(): mixed
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            self::fail('failed to open an in-memory stream to stand in for stdio');
        }

        return $stream;
    }

    public function testDisabledByDefault(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--context' => 'web']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('MCP is disabled', $tester->getDisplay());
    }

    public function testExplicitlyDisabledFailsTheSameWay(): void
    {
        Config::set('mcp.enabled', false, true);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--context' => 'web']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('MCP is disabled', $tester->getDisplay());
    }

    public function testExplicitContextResolvesTheContainer(): void
    {
        Config::set('mcp.enabled', true, true);

        $tester = $this->tester($this->fakeStdioStream(), $this->fakeStdioStream());
        $exitCode = $tester->execute(['--context' => 'web']);

        $this->assertContains($exitCode, [0, 1]);
        $this->assertStringNotContainsString('MCP is disabled', $tester->getDisplay());
        $this->assertStringNotContainsString('Could not resolve the DI container', $tester->getDisplay());
    }

    public function testEmptyExplicitContextFallsBackToConfiguredDefaultContext(): void
    {
        $originalDefaultContext = Config::has('core.default_context')
            ? Config::getString('core.default_context')
            : null;
        Config::set('mcp.enabled', true, true);
        Config::set('core.default_context', 'web', true);

        try {
            $tester = $this->tester($this->fakeStdioStream(), $this->fakeStdioStream());
            $exitCode = $tester->execute(['--context' => '']);

            $this->assertContains($exitCode, [0, 1]);
            $this->assertStringNotContainsString('Could not resolve the DI container', $tester->getDisplay());
        } finally {
            if ($originalDefaultContext === null) {
                Config::remove('core.default_context');
            } else {
                Config::set('core.default_context', $originalDefaultContext, true);
            }
        }
    }

    public function testMissingContextOptionFallsBackToConfiguredDefaultContext(): void
    {
        $originalDefaultContext = Config::has('core.default_context')
            ? Config::getString('core.default_context')
            : null;
        Config::set('mcp.enabled', true, true);
        Config::set('core.default_context', 'web', true);

        try {
            $tester = $this->tester($this->fakeStdioStream(), $this->fakeStdioStream());
            $exitCode = $tester->execute([]);

            $this->assertContains($exitCode, [0, 1]);
            $this->assertStringNotContainsString('Could not resolve the DI container', $tester->getDisplay());
        } finally {
            if ($originalDefaultContext === null) {
                Config::remove('core.default_context');
            } else {
                Config::set('core.default_context', $originalDefaultContext, true);
            }
        }
    }
}
