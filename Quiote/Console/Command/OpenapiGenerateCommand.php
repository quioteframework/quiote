<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Openapi\OpenApiGenerator;
use Quiote\Openapi\OpenApiOptions;
use Quiote\Routing\Compiler\RouteCollectionIntrospector;
use Quiote\Support\Compiler\Diagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes an OpenAPI 3.1 description of the app, derived from the live routing
 * service's route collection and each action's own validator declarations --
 * see {@see OpenApiGenerator} for what is and isn't derivable. Reads routes the
 * same way `routes:list` does (whatever the configured Routing class returns,
 * attribute- and file-declared alike), so the two never disagree about which
 * routes exist.
 *
 * Document metadata comes from `core.openapi.*` (see
 * {@see OpenApiOptions::fromConfig()}); the options here override it per run,
 * for a CI job that publishes one spec per server or per module.
 * @since      1.2.5
 */
#[AsCommand(name: 'openapi:generate', description: 'Generate an OpenAPI 3.1 document from the route table and the actions\' validators')]
final class OpenapiGenerateCommand extends AbstractAppCommand
{
	private const array FORMATS = ['json', 'yaml'];

	protected function configure(): void
	{
		$this->configureAppOptions();
		$this
			->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context to resolve the routing service from (defaults to core.default_context, else "web")')
			->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the document to this file instead of stdout')
			->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: ' . implode(', ', self::FORMATS) . ' (defaults to the --output extension, else json)')
			->addOption('title', null, InputOption::VALUE_REQUIRED, 'info.title (overrides core.openapi.title)')
			->addOption('api-version', null, InputOption::VALUE_REQUIRED, 'info.version (overrides core.openapi.version)')
			->addOption('server', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Server URL; repeatable (overrides core.openapi.servers)')
			->addOption('module', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only describe routes of this module; repeatable')
			->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'fnmatch pattern of route names to leave out; repeatable')
			->addOption('no-docblocks', null, InputOption::VALUE_NONE, 'Do not use action docblocks as operation summaries/descriptions');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$this->bootstrapApp($input);
		$io = new SymfonyStyle($input, $output);

		$outputPath = self::stringOption($input->getOption('output'));
		$format = $this->resolveFormat($input, $outputPath);
		if ($format === null) {
			$io->error(sprintf('Unknown --format "%s"; expected one of: %s.', (string) self::stringOption($input->getOption('format')), implode(', ', self::FORMATS)));
			return self::FAILURE;
		}

		$contextName = self::stringOption($input->getOption('context')) ?? Config::getString('core.default_context', 'web');
		try {
			$context = Context::getInstance($contextName);
			$routing = $context->getRouting();
			$controller = $context->getController();
		} catch (\Throwable $e) {
			$io->error(sprintf('Could not resolve the routing service for context "%s": %s', $contextName, $e->getMessage()));
			return self::FAILURE;
		}

		$routes = (new RouteCollectionIntrospector())->toDefinitions(
			$routing->getRouteCollection(),
			sprintf('context "%s" routing service', $contextName),
		);

		$generator = new OpenApiGenerator();
		$document = $generator->generate($routes, $controller, $this->options($input));

		$encoded = $this->encode($document, $format);
		if ($encoded === null) {
			$io->error('Could not encode the generated document.');
			return self::FAILURE;
		}

		if ($outputPath !== null) {
			if (file_put_contents($outputPath, $encoded . "\n") === false) {
				$io->error(sprintf('Could not write "%s".', $outputPath));
				return self::FAILURE;
			}
			$paths = $document['paths'] ?? [];
			$io->success(sprintf('Wrote %s (%d path%s).', $outputPath, is_array($paths) ? count($paths) : 0, (is_array($paths) ? count($paths) : 0) === 1 ? '' : 's'));
		} else {
			$output->writeln($encoded);
		}

		$this->renderDiagnostics($io, $generator->getDiagnostics(), $outputPath !== null);

		return self::SUCCESS;
	}

	private function options(InputInterface $input): OpenApiOptions
	{
		$configured = OpenApiOptions::fromConfig();
		$servers = self::stringListOption($input->getOption('server'));
		$modules = self::stringListOption($input->getOption('module'));
		$excludes = self::stringListOption($input->getOption('exclude'));

		return new OpenApiOptions(
			title: self::stringOption($input->getOption('title')) ?? $configured->title,
			version: self::stringOption($input->getOption('api-version')) ?? $configured->version,
			description: $configured->description,
			servers: $servers !== [] ? OpenApiOptions::normalizeServers($servers) : $configured->servers,
			excludeRoutes: $excludes !== [] ? $excludes : $configured->excludeRoutes,
			modules: $modules !== [] ? $modules : $configured->modules,
			problemResponses: $configured->problemResponses,
			useActionDocblocks: $input->getOption('no-docblocks') === true ? false : $configured->useActionDocblocks,
		);
	}

	/** @return 'json'|'yaml'|null Null when an explicit --format isn't one we emit. */
	private function resolveFormat(InputInterface $input, ?string $outputPath): ?string
	{
		$explicit = self::stringOption($input->getOption('format'));
		if ($explicit !== null) {
			$explicit = strtolower($explicit);

			return match ($explicit) {
				'json' => 'json',
				'yaml', 'yml' => 'yaml',
				default => null,
			};
		}

		$extension = $outputPath !== null ? strtolower(pathinfo($outputPath, PATHINFO_EXTENSION)) : '';

		return in_array($extension, ['yaml', 'yml'], true) ? 'yaml' : 'json';
	}

	/**
	 * @param array<string, mixed> $document
	 * @param 'json'|'yaml' $format
	 */
	private function encode(array $document, string $format): ?string
	{
		// `paths` is an OpenAPI object; an app with nothing to describe would
		// otherwise encode it as an empty JSON array.
		if (($document['paths'] ?? null) === []) {
			$document['paths'] = new \stdClass();
		}

		if ($format === 'yaml') {
			// DUMP_OBJECT_AS_MAP so the deliberate "unconstrained schema"
			// stdClass values (see OpenApiGenerator::responseSchema()) come out
			// as `{}` rather than a PHP object tag. Note that response status
			// keys come out unquoted (`200:`): PHP normalizes numeric array keys
			// to int and YAML has no way to ask for a quoted key back. Reading
			// the file as YAML and converting it to JSON stringifies them again,
			// which is what a spec consumer does; --format=json emits `"200"`
			// directly for anything that insists.
			return Yaml::dump($document, 8, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
		}

		$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		return $json === false ? null : $json;
	}

	/**
	 * @param Diagnostic[] $diagnostics
	 * @param bool $documentWrittenToFile Whether the document went to a file, leaving stdout free for messages.
	 */
	private function renderDiagnostics(SymfonyStyle $io, array $diagnostics, bool $documentWrittenToFile): void
	{
		if ($diagnostics === [] || !$documentWrittenToFile) {
			// With the document on stdout, diagnostics would corrupt it; a
			// caller that wants both passes --output.
			return;
		}
		foreach ($diagnostics as $diagnostic) {
			$io->warning(sprintf('[%s] %s (%s)', $diagnostic->code, $diagnostic->message, $diagnostic->where));
		}
	}

	/** Narrows Symfony's mixed option value to a non-empty string, or null when unset. */
	private static function stringOption(mixed $value): ?string
	{
		return (is_string($value) && $value !== '') ? $value : null;
	}

	/**
	 * @return list<string>
	 */
	private static function stringListOption(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}
		$values = [];
		foreach ($value as $entry) {
			if (is_string($entry) && $entry !== '') {
				$values[] = $entry;
			}
		}

		return $values;
	}
}
