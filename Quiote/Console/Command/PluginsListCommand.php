<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Plugin\PluginManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists every plugin registered for the app -- i.e. exactly what
 * {@see PluginManager::registeredPlugins()} holds once `Quiote::bootstrap()`
 * has read the `plugins` config key (plus any programmatic
 * `PluginManager::add()` calls a bootstrap file made) and invoked
 * `register()` on each.
 *
 * Listed in declared/registration order, not sorted -- that order is itself
 * meaningful (see {@see PluginManager::bootFromConfig()}: `register()` runs
 * in this order, and a plugin that depends on another's contribution having
 * already run cares which came first).
 *
 * Does not include `quioteframework/csrf`'s plugin: {@see \Quiote\Quiote::bootstrap()}
 * registers it directly (a deliberate opt-out-not-opt-in security default), never
 * through {@see PluginManager}, so it never appears here even though `middleware:list`
 * shows its middleware as active.
 * @since      1.0.0
 */
#[AsCommand(name: 'plugins:list', description: 'List plugins registered with the app')]
final class PluginsListCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapApp($input);
        $io = new SymfonyStyle($input, $output);

        $plugins = [];
        foreach (PluginManager::registeredPlugins() as $class => $plugin) {
            $plugins[] = [
                'name' => PluginManager::resolveName($plugin),
                'class' => $class,
            ];
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode(['plugins' => $plugins], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"plugins":[]}');
            return self::SUCCESS;
        }

        if (!$plugins) {
            $io->warning('No plugins registered.');
            return self::SUCCESS;
        }

        $io->table(
            ['Name', 'Class'],
            array_map(static fn(array $plugin) => [$plugin['name'], $plugin['class']], $plugins)
        );

        return self::SUCCESS;
    }
}
