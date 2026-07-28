<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Console\Command\Scaffold\ActionWriter;
use Quiote\Console\Command\Scaffold\GeneratorSupport;
use Quiote\Exception\ConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scaffolds an empty module (Actions/Views/Templates directories) -- a
 * module has no class of its own, it's a directory convention (see
 * `ModuleActionDiscovery`), so unlike the other `make:*` commands this one
 * has nothing to templatize beyond the directories themselves and an
 * optional seed Action via --with-index.
 * @since      1.0.0
 */
#[AsCommand(name: 'make:module', description: 'Scaffold an empty module directory tree')]
final class MakeModuleCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Module name, e.g. "Blog"')
            ->addOption('with-index', null, InputOption::VALUE_NONE, 'Also seed an IndexAction/View/Template trio')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files (only relevant with --with-index)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->bootstrapApp($input);

        try {
            $name = GeneratorSupport::requireString($input->getArgument('name'), 'name');
            GeneratorSupport::validateClassNameSegment($name);
            $appDir = GeneratorSupport::appDir();
            $moduleDir = "{$appDir}/Modules/{$name}";

            foreach (['Actions', 'Views', 'Templates'] as $subdir) {
                $path = "{$moduleDir}/{$subdir}";
                if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
                    throw new ConfigurationException(sprintf('Could not create directory "%s".', $path));
                }
            }

            if ($input->getOption('with-index')) {
                $writer = new ActionWriter($appDir, GeneratorSupport::appNamespace(), $name);
                $writer->write('Index', ['GET'], true, ['html'], (bool) $input->getOption('force'));
            }
        } catch (ConfigurationException $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        $io->success(sprintf('Created module "%s".', $name));
        return self::SUCCESS;
    }
}
