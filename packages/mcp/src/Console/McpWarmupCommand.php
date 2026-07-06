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
 * Pre-populates the plain-class attribute-discovery cache (see
 * {@see McpServer::buildDiscoveryCache()}) by building the SDK server once
 * offline, so the first real `mcp:serve`/HTTP request in a freshly started
 * process hits the file-backed cache instead of paying the filesystem-walk +
 * reflection cost of `Mcp\Capability\Discovery\Discoverer` itself. A no-op
 * (but still successful) when `mcp.discover_attributes` or
 * `mcp.discovery_cache` is off -- nothing to warm.
 */
#[AsCommand(name: 'mcp:warmup', description: 'Pre-populate the MCP attribute-discovery cache')]
final class McpWarmupCommand extends AbstractAppCommand
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

        if (!$config->discoverAttributes) {
            $io->writeln('<comment>mcp.discover_attributes is off; nothing to warm.</comment>');
            return self::SUCCESS;
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
            (new McpServer($container, $contextName))->build($config);
        } catch (\Throwable $e) {
            $io->error('Failed to warm MCP discovery cache: ' . $e->getMessage());
            return self::FAILURE;
        }

        $io->success($config->discoveryCache
            ? 'MCP attribute-discovery cache warmed.'
            : 'MCP attribute discovery ran (mcp.discovery_cache is off, so nothing was cached).');
        return self::SUCCESS;
    }
}
