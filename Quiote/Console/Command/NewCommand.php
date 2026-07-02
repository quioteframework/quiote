<?php
namespace Quiote\Console\Command;

use Quiote\Exception\ConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scaffolds a new Quiote application: a Default module (Index/About/Boom
 * actions), the minimal config set needed to boot (settings, factories,
 * routing, output_types -- see docs/CONFIG_SYSTEM_REWRITE_PLAN.md for why
 * config_handlers.xml/compile.xml/translation.xml/databases.xml can all be
 * omitted and still boot cleanly), and a FrankenPHP-ready pub/index.php.
 *
 * Deliberately pre-bootstrap: this command never touches Quiote\Context or
 * Quiote::bootstrap() -- it only writes files. The generated app is
 * self-contained (its own spl_autoload_register in pub/index.php) so it
 * needs no composer.json of its own; it just needs to find *some*
 * vendor/autoload.php that has quioteframework/quiote in it. Since the
 * target directory can be anywhere (e.g. /tmp, or a samples/ dir inside
 * this very monorepo) with no vendor/ of its own, walking upward from
 * pub/index.php alone cannot be relied on to find one -- so we resolve the
 * autoloader actually in effect for *this* `quiote` invocation (mirroring
 * bin/quiote's own two candidates) and bake that absolute path into the
 * generated front controller as the first candidate; an upward walk stays
 * as a fallback for a generated app later moved next to its own vendor/.
 * @since      1.0.0
 */
#[AsCommand(name: 'new', description: 'Scaffold a new Quiote application')]
final class NewCommand extends Command
{
	protected function configure(): void
	{
		$this
			->addArgument('path', InputArgument::REQUIRED, 'Directory to create the application in')
			->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'PSR-4 namespace prefix for the app', 'App')
			->addOption('config-format', null, InputOption::VALUE_REQUIRED, 'Format for settings/factories: php, yaml, or xml', 'php')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Write into a non-empty directory');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$path = rtrim((string) $input->getArgument('path'), '/');
		$namespace = trim((string) $input->getOption('namespace'), '\\');
		$format = (string) $input->getOption('config-format');

		if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $namespace)) {
			$io->error(sprintf('"%s" is not a valid PSR-4 namespace prefix (expected e.g. "App", "SampleApp").', $namespace));
			return Command::FAILURE;
		}
		if (!in_array($format, ['php', 'yaml', 'xml'], true)) {
			$io->error(sprintf('Unknown --config-format "%s"; expected one of: php, yaml, xml.', $format));
			return Command::FAILURE;
		}

		if (is_dir($path) && !$input->getOption('force') && (new \FilesystemIterator($path))->valid()) {
			$io->error(sprintf('"%s" already exists and is not empty. Use --force to write into it anyway.', $path));
			return Command::FAILURE;
		}

		try {
			$writer = new Scaffold\AppWriter($path, $namespace, $format, $this->resolveActiveAutoloadPath());
			$writer->write();
		} catch (ConfigurationException $e) {
			$io->error($e->getMessage());
			return Command::FAILURE;
		}

		$io->success(sprintf('Created a new Quiote application in "%s".', $path));
		$io->writeln([
			'Next steps:',
			sprintf('  cd %s', $path),
			'  php -S localhost:8000 -t pub pub/index.php   # quick smoke test, or use FrankenPHP:',
			'  frankenphp php-server --root pub',
			'',
			'Routes: GET /, GET /about, GET /boom (deliberately throws -- try it with',
			'core.developer_exceptions=true in Config/settings.' . $format . ' to see the Whoops page).',
		]);

		return Command::SUCCESS;
	}

	/** Same two candidates bin/quiote itself checks -- see the class docblock. */
	private function resolveActiveAutoloadPath(): ?string
	{
		$packageRoot = dirname(__DIR__, 3);
		foreach ([$packageRoot . '/vendor/autoload.php', dirname($packageRoot, 2) . '/autoload.php'] as $candidate) {
			if (is_file($candidate)) {
				return realpath($candidate);
			}
		}
		return null;
	}
}
