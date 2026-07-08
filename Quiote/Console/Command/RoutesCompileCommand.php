<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Config\Config;
use Quiote\Introspection\AppIntrospectionArtifactWriter;
use Quiote\Introspection\AppIntrospectionCompiler;
use Quiote\Support\Compiler\Diagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates the `cache/introspection/app.json` artifact an editor extension
 * reads directly (no PHP spawn) on its warm path -- routes, modules, Action/
 * View/Template triads, diagnostics, a dependency manifest, and shadowed-
 * config info -- and prints the same payload to stdout. This is the single
 * generator behind that artifact; a future `overview`/`diagnostics`
 * capability elsewhere is meant to reuse `AppIntrospectionCompiler` rather
 * than reimplement any of this.
 * @since      1.0.0
 */
#[AsCommand(name: 'routes:compile', description: 'Compile route/module/triad introspection data into cache/introspection/app.json')]
final class RoutesCompileCommand extends AbstractAppCommand
{
	protected function configure(): void
	{
		$this->configureAppOptions();
		$this
			->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context to resolve the routing service from (defaults to core.default_context, else "web")')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the compiled artifact as JSON (always written to disk regardless)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$this->bootstrapApp($input);
		$io = new SymfonyStyle($input, $output);

		$contextOption = $input->getOption('context');
		$context = is_string($contextOption) ? $contextOption : Config::getString('core.default_context', 'web');

		try {
			$artifact = (new AppIntrospectionCompiler())->compile($context);
		} catch (\Throwable $e) {
			$io->error(sprintf('Could not compile introspection data for context "%s": %s', $context, $e->getMessage()));
			return self::FAILURE;
		}

		$artifact = ['generated_at' => date('c')] + $artifact;
		$target = rtrim(Config::getString('core.cache_dir'), '/') . '/introspection/app.json';

		try {
			(new AppIntrospectionArtifactWriter())->write($artifact, $target);
		} catch (\Throwable $e) {
			$io->error('Could not write introspection artifact: ' . $e->getMessage());
			return self::FAILURE;
		}

		if ($input->getOption('json')) {
			$output->writeln(json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
		} else {
			$io->success(sprintf(
				'Compiled %d route(s), %d module(s), %d triad(s) -> %s',
				count($artifact['routes']),
				count($artifact['modules']),
				count($artifact['triads']),
				$target,
			));
			$this->renderDiagnostics($io, $artifact['diagnostics']);
		}

		return $this->exitCodeFor($artifact['diagnostics']);
	}

	/**
	 * @param list<array{severity: string, code: string, message: string, file: string}> $diagnostics
	 */
	private function renderDiagnostics(SymfonyStyle $io, array $diagnostics): void
	{
		foreach ($diagnostics as $diagnostic) {
			$message = sprintf('[%s] %s (%s)', $diagnostic['code'], $diagnostic['message'], $diagnostic['file']);
			if ($diagnostic['severity'] === Diagnostic::SEVERITY_ERROR) {
				$io->error($message);
			} else {
				$io->warning($message);
			}
		}
	}

	/**
	 * @param list<array{severity: string}> $diagnostics
	 */
	private function exitCodeFor(array $diagnostics): int
	{
		foreach ($diagnostics as $diagnostic) {
			if ($diagnostic['severity'] === Diagnostic::SEVERITY_ERROR) {
				return self::FAILURE;
			}
		}
		return self::SUCCESS;
	}
}
