<?php
declare(strict_types=1);

namespace Quiote\Mcp\Console;

use Quiote\Config\Config;
use Quiote\Console\Command\AbstractAppCommand;
use Quiote\Context;
use Quiote\Mcp\McpConfig;
use Quiote\Mcp\McpServer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs this app as an MCP server over stdio (docs/MCP_SERVER_PLAN.md §5.2) --
 * the transport local clients (Claude Desktop, IDEs) launch as a subprocess,
 * and the fastest end-to-end path for the capability (phase 1: no HTTP/auth
 * surface). Registered via {@see \Quiote\Mcp\McpPlugin}.
 */
#[AsCommand(name: 'mcp:serve', description: 'Run this app as an MCP server over stdio')]
final class McpServeCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context to resolve the DI container from (defaults to core.default_context, else "web")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $config = McpConfig::fromConfig();
        if (!$config->enabled) {
            $io->error('MCP is disabled. Set the "mcp.enabled" setting to true (and add McpPlugin to your `plugins` config) to use this command.');
            return self::FAILURE;
        }

        $contextName = (string) ($input->getOption('context') ?? Config::get('core.default_context', 'web'));
        try {
            $container = Context::getInstance($contextName)->getContainer();
        } catch (\Throwable $e) {
            $io->error(sprintf('Could not resolve the DI container for context "%s": %s', $contextName, $e->getMessage()));
            return self::FAILURE;
        }

        try {
            return (new McpServer($container, $contextName))->runStdio($config);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
