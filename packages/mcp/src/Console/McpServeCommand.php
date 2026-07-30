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
 * Runs this app as an MCP server over stdio -- the transport local clients
 * (Claude Desktop, IDEs) launch as a subprocess, with no HTTP/auth surface.
 * Registered via {@see \Quiote\Mcp\McpPlugin}.
 */
#[AsCommand(name: 'mcp:serve', description: 'Run this app as an MCP server over stdio')]
final class McpServeCommand extends AbstractAppCommand
{
    /**
     * `$stdioInput`/`$stdioOutput` default to null (real STDIN/STDOUT in
     * production) -- the parameters exist only so tests can hand
     * `runStdio()` throwaway in-memory streams instead of the process's real
     * stdio, which {@see McpServer::runStdio()}'s docblock explains the need
     * for.
     *
     * @param resource|null $stdioInput
     * @param resource|null $stdioOutput
     */
    public function __construct(
        private readonly mixed $stdioInput = null,
        private readonly mixed $stdioOutput = null,
    ) {
        parent::__construct();
    }

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

        $contextOption = $input->getOption('context');
        $contextName = is_string($contextOption) && $contextOption !== ''
            ? $contextOption
            : Config::getString('core.default_context', 'web');
        try {
            $container = Context::getInstance($contextName)->getContainer();
        } catch (\Throwable $e) {
            $io->error(sprintf('Could not resolve the DI container for context "%s": %s', $contextName, $e->getMessage()));
            return self::FAILURE;
        }

        try {
            return (new McpServer($container, $contextName))->runStdio($config, $this->stdioInput, $this->stdioOutput);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
