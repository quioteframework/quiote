<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Config\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints framework/app diagnostic info. Deliberately trivial: it exists to
 * prove the bootstrap-in-console-context path (app-dir resolution +
 * Quiote::bootstrap()) works before building real commands (routes:list,
 * routes:compile) on top of it.
 * @since      1.0.0
 */
#[AsCommand(name: 'about', description: 'Display framework and application information')]
final class AboutCommand extends AbstractAppCommand
{
	protected function configure(): void
	{
		$this->configureAppOptions();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$this->bootstrapApp($input);

		$io = new SymfonyStyle($input, $output);
		$io->table([], [
			['Quiote version', Config::getString('quiote.version', 'dev')],
			['Application directory', Config::getString('core.app_dir')],
			['Environment', Config::getString('core.environment')],
			['Module directory', Config::getString('core.module_dir')],
			['Namespace prefix', Config::getString('core.namespace_prefix', 'App')],
		]);

		return self::SUCCESS;
	}
}
