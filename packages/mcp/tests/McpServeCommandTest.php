<?php

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Quiote\Config\Config;
use Quiote\Mcp\Console\McpServeCommand;
use Quiote\Testing\PhpUnitTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `mcp:serve` is opt-in-gated on `mcp.enabled`; this only exercises that
 * gate. The enabled path blocks on the stdio
 * transport's read loop and is exercised end-to-end by McpServerTest instead
 * (via InMemoryTransport, driving McpServer directly).
 */
final class McpServeCommandTest extends PhpUnitTestCase
{
    #[Before]
    #[After]
    public function resetMcpEnabled(): void
    {
        Config::remove('mcp.enabled');
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new McpServeCommand());
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
}
