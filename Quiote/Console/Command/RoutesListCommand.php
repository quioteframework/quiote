<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Config\Config;
use Quiote\Context;
use Quiote\Routing\Compiler\AttributeRouteScanner;
use Quiote\Support\Compiler\Diagnostic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists every route the app's actual configured Routing service knows
 * about -- i.e. whatever `getRouteCollection()`/`getMeta()` on the class
 * factories.xml/.yaml/.php names for the "routing" entry returns. That
 * class decides for itself whether it's hand-written routes
 * (`Routing::build()`), attribute routes only, or both merged via
 * `AttributeRoutes::mergeInto()` (see samples/app/Routing/AppRouting.php) --
 * this command is a read-only view onto the live result, not a second
 * opinion sourced only from `#[Route]` attributes.
 *
 * Attribute-declared routes are additionally scanned (independently of
 * whatever the app's Routing class does with them) to surface authoring
 * diagnostics -- duplicate route names/paths among #[Route] attributes are
 * worth flagging whether or not the app actually merges them in -- and to
 * label each listed route's Source column "Attribute" (its name was
 * declared via #[Route]) or "File" (anything else: Routing::build(),
 * routing.xml, a programmatic builder, ...).
 * @since      1.0.0
 */
#[AsCommand(name: 'routes:list', description: "List routes known to the app's configured routing service")]
final class RoutesListCommand extends AbstractAppCommand
{
	private const SORT_KEYS = ['name', 'path', 'module', 'action'];

	protected function configure(): void
	{
		$this->configureAppOptions();
		$this
			->addOption('context', null, InputOption::VALUE_REQUIRED, 'Context to resolve the routing service from (defaults to core.default_context, else "web")')
			->addOption('module', null, InputOption::VALUE_REQUIRED, 'Only show routes belonging to this module')
			->addOption('action', null, InputOption::VALUE_REQUIRED, 'Only show routes resolving to this action')
			->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort by one of: ' . implode(', ', self::SORT_KEYS), 'name')
			->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON instead of a table');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$this->bootstrapApp($input);
		$io = new SymfonyStyle($input, $output);

		$sort = (string) $input->getOption('sort');
		if (!in_array($sort, self::SORT_KEYS, true)) {
			$io->error(sprintf('Unknown --sort "%s"; expected one of: %s.', $sort, implode(', ', self::SORT_KEYS)));
			return self::FAILURE;
		}

		$context = (string) ($input->getOption('context') ?? Config::getString('core.default_context', 'web'));
		try {
			$routing = Context::getInstance($context)->getRouting();
		} catch (\Throwable $e) {
			$io->error(sprintf('Could not resolve the routing service for context "%s": %s', $context, $e->getMessage()));
			return self::FAILURE;
		}

		[$attributeRouteNames, $diagnostics] = $this->scanAttributeRoutes();

		$routes = [];
		foreach ($routing->getRouteCollection() as $name => $route) {
			$routes[] = [
				'name' => $name,
				'path' => $route->getPath(),
				'methods' => $route->getMethods(),
				'module' => (string) ($route->getDefault('_module') ?? ''),
				'action' => (string) ($route->getDefault('_action') ?? ''),
				'outputType' => $route->getDefault('_output_type'),
				'source' => in_array($name, $attributeRouteNames, true) ? 'Attribute' : 'File',
			];
		}

		$module = $input->getOption('module');
		if ($module !== null) {
			$routes = array_values(array_filter($routes, static fn(array $route) => strcasecmp($route['module'], $module) === 0));
		}

		$action = $input->getOption('action');
		if ($action !== null) {
			$routes = array_values(array_filter($routes, static fn(array $route) => strcasecmp($route['action'], $action) === 0));
		}

		usort($routes, static fn(array $a, array $b) => $a[$sort] <=> $b[$sort]);

		if ($input->getOption('json')) {
			$output->writeln(json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');
			return $this->exitCodeFor($diagnostics);
		}

		$this->renderDiagnostics($io, $diagnostics);

		if (!$routes) {
			$io->warning('No routes found.');
			return $this->exitCodeFor($diagnostics);
		}

		$io->table(
			['Name', 'Path', 'Methods', 'Module', 'Action', 'Output type', 'Source'],
			array_map(static fn(array $route) => [
				$route['name'],
				$route['path'],
				$route['methods'] ? implode('|', $route['methods']) : 'ANY',
				$route['module'],
				$route['action'],
				$route['outputType'] ?? '',
				$route['source'],
			], $routes)
		);

		return $this->exitCodeFor($diagnostics);
	}

	/**
	 * @return array{0: string[], 1: Diagnostic[]} Route names declared via
	 *         #[Route] attributes, and any diagnostics from that scan.
	 */
	private function scanAttributeRoutes(): array
	{
		$scanner = new AttributeRouteScanner();
		$plan = $scanner->scan();
		return [array_map(static fn($route) => $route->name, $plan->routes), $scanner->getDiagnostics()];
	}

	/**
	 * @param Diagnostic[] $diagnostics
	 */
	private function renderDiagnostics(SymfonyStyle $io, array $diagnostics): void
	{
		foreach ($diagnostics as $diagnostic) {
			$message = sprintf('[%s] %s (%s)', $diagnostic->code, $diagnostic->message, $diagnostic->where);
			if ($diagnostic->severity === Diagnostic::SEVERITY_ERROR) {
				$io->error($message);
			} else {
				$io->warning($message);
			}
		}
	}

	/**
	 * @param Diagnostic[] $diagnostics
	 */
	private function exitCodeFor(array $diagnostics): int
	{
		foreach ($diagnostics as $diagnostic) {
			if ($diagnostic->severity === Diagnostic::SEVERITY_ERROR) {
				return self::FAILURE;
			}
		}
		return self::SUCCESS;
	}
}
