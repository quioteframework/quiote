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
 * Scaffolds an Action (and, unless --no-view, a matching View + Template)
 * inside an existing app's Modules/{module}/ tree -- the "inside an app"
 * counterpart to `new` scaffolding a whole app from nothing.
 * @since      1.0.0
 */
#[AsCommand(name: 'make:action', description: 'Scaffold an Action (and matching View) inside a module')]
final class MakeActionCommand extends AbstractAppCommand
{
    private const KNOWN_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'POST', 'PUT', 'PATCH', 'DELETE'];

    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Action name, e.g. "Post"')
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Module to create the action in', 'Default')
            ->addOption('methods', null, InputOption::VALUE_REQUIRED, 'Comma-separated HTTP methods this action handles', 'GET')
            ->addOption('output-types', null, InputOption::VALUE_REQUIRED, 'Comma-separated output types the View should support', 'html')
            ->addOption('no-view', null, InputOption::VALUE_NONE, 'Do not generate a matching View/Template')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->bootstrapApp($input);

        $withView = !$input->getOption('no-view');
        $force = (bool) $input->getOption('force');

        try {
            $name = GeneratorSupport::requireString($input->getArgument('name'), 'name');
            $module = GeneratorSupport::requireString($input->getOption('module'), '--module');
            GeneratorSupport::validateClassNameSegment($name);
            GeneratorSupport::validateClassNameSegment($module);
            $methods = $this->parseMethods(GeneratorSupport::requireString($input->getOption('methods'), '--methods'));
            $outputTypes = $this->parseOutputTypes(GeneratorSupport::requireString($input->getOption('output-types'), '--output-types'), $withView);

            $writer = new ActionWriter(GeneratorSupport::appDir(), GeneratorSupport::appNamespace(), $module);
            $warnings = $writer->write($name, $methods, $withView, $outputTypes, $force);
        } catch (ConfigurationException $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        foreach ($warnings as $warning) {
            $io->warning($warning);
        }

        $io->success(sprintf('Created %sAction in module "%s".', $name, $module));
        return self::SUCCESS;
    }

    /** @return list<string> */
    private function parseMethods(string $raw): array
    {
        $methods = array_values(array_unique(array_map('strtoupper', array_filter(array_map('trim', explode(',', $raw))))));
        if ($methods === []) {
            throw new ConfigurationException('--methods must list at least one HTTP method.');
        }
        foreach ($methods as $method) {
            if (!in_array($method, self::KNOWN_METHODS, true)) {
                throw new ConfigurationException(sprintf(
                    'Unknown HTTP method "%s" in --methods. Supported: %s.',
                    $method,
                    implode(', ', self::KNOWN_METHODS)
                ));
            }
        }
        return $methods;
    }

    /** @return list<string> */
    private function parseOutputTypes(string $raw, bool $withView): array
    {
        $types = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($raw))))));
        if ($types === []) {
            throw new ConfigurationException('--output-types must list at least one output type.');
        }
        if (!$withView && $types !== ['html']) {
            throw new ConfigurationException('--output-types has no effect with --no-view (there is no View to attach it to).');
        }
        foreach ($types as $type) {
            if (!preg_match('/^[a-z][a-z0-9]*$/', $type)) {
                throw new ConfigurationException(sprintf('"%s" is not a valid output type name.', $type));
            }
        }
        return $types;
    }
}
