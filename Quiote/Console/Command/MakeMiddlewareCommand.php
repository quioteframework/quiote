<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Console\Command\Scaffold\GeneratorSupport;
use Quiote\Exception\ConfigurationException;
use Quiote\Middleware\Compiler\MiddlewarePhase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scaffolds a PSR-15 middleware class carrying a
 * {@see \Quiote\Middleware\Attribute\Middleware} attribute -- app-owned
 * classes need no explicit registration beyond that attribute, since
 * `MiddlewareAttributeScanner` picks them up automatically.
 * @since      1.0.0
 */
#[AsCommand(name: 'make:middleware', description: 'Scaffold a PSR-15 middleware class')]
final class MakeMiddlewareCommand extends AbstractAppCommand
{
    protected function configure(): void
    {
        $this->configureAppOptions();
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Middleware name, e.g. "RequestId"')
            ->addOption('phase', null, InputOption::VALUE_REQUIRED, 'Middleware phase (' . implode(', ', MiddlewarePhase::ORDER) . ')', 'before_action')
            ->addOption('priority', null, InputOption::VALUE_REQUIRED, 'Ordering priority within the phase', '0')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Run after this middleware class/name')
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Run before this middleware class/name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->bootstrapApp($input);

        $force = (bool) $input->getOption('force');

        try {
            $name = GeneratorSupport::requireString($input->getArgument('name'), 'name');
            $phase = GeneratorSupport::requireString($input->getOption('phase'), '--phase');
            $priorityRaw = GeneratorSupport::requireString($input->getOption('priority'), '--priority');
            $after = $input->getOption('after');
            $before = $input->getOption('before');

            GeneratorSupport::validateClassNameSegment($name);
            MiddlewarePhase::rank($phase);
            if (!ctype_digit(ltrim($priorityRaw, '-')) && !is_numeric($priorityRaw)) {
                throw new ConfigurationException(sprintf('"%s" is not a valid --priority (expected an integer).', $priorityRaw));
            }
            $priority = (int) $priorityRaw;

            $namespace = GeneratorSupport::appNamespace();
            $path = GeneratorSupport::appDir() . "/Middleware/{$name}Middleware.php";
            GeneratorSupport::guardOverwrite($path, $force);
            GeneratorSupport::writeFile($path, $this->middlewarePhp($namespace, $name, $phase, $priority, is_string($after) ? $after : null, is_string($before) ? $before : null));
        } catch (\InvalidArgumentException|ConfigurationException $e) {
            $io->error($e->getMessage());
            return self::FAILURE;
        }

        $io->success(sprintf('Created %sMiddleware.', $name));
        return self::SUCCESS;
    }

    private function middlewarePhp(string $namespace, string $name, string $phase, int $priority, ?string $after, ?string $before): string
    {
        $attributeArgs = "phase: '{$phase}', priority: {$priority}";
        if ($after !== null) {
            $attributeArgs .= ", after: '{$after}'";
        }
        if ($before !== null) {
            $attributeArgs .= ", before: '{$before}'";
        }

        return <<<PHP
<?php
namespace {$namespace}\\Middleware;

use Psr\\Http\\Message\\ResponseInterface;
use Psr\\Http\\Message\\ServerRequestInterface;
use Psr\\Http\\Server\\MiddlewareInterface;
use Psr\\Http\\Server\\RequestHandlerInterface;
use Quiote\\Middleware\\Attribute\\Middleware;

#[Middleware({$attributeArgs})]
class {$name}Middleware implements MiddlewareInterface
{
	public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
	{
		return \$handler->handle(\$request);
	}
}

PHP;
    }
}
