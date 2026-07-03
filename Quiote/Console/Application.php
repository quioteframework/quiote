<?php
namespace Quiote\Console;

use Quiote\Console\Command\AboutCommand;
use Quiote\Console\Command\CacheWarmupCommand;
use Quiote\Console\Command\NewCommand;
use Quiote\Console\Command\RoutesListCommand;
use Quiote\Console\Command\TelemetryDashboardCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * The `quiote` CLI. `new` is pre-bootstrap (it scaffolds an app from
 * nothing, so there is no Quiote\Context to build yet); `about` and
 * `routes:list` bootstrap an existing app via AbstractAppCommand's app-dir
 * resolution + Quiote::bootstrap() wiring -- see
 * docs/ROUTING_AND_CLI_PLAN.md (B1-B3). `telemetry:dashboard` is only
 * registered when `symfony/tui` (a `require-dev`/`suggest`-only dependency,
 * see docs/TELEMETRY_DASHBOARD_PLAN.md) is actually installed -- a
 * production install without it simply doesn't offer the command, mirroring
 * how the `open-telemetry/*` packages are optional everywhere else.
 * @since      1.0.0
 */
final class Application extends SymfonyApplication
{
	public function __construct()
	{
		parent::__construct('quiote', self::version());
		$this->addCommand(new NewCommand());
		$this->addCommand(new AboutCommand());
		$this->addCommand(new RoutesListCommand());
		$this->addCommand(new CacheWarmupCommand());
		if (class_exists(\Symfony\Component\Tui\Tui::class)) {
			$this->addCommand(new TelemetryDashboardCommand());
		}
		$this->addContributedCommands();
	}

	/**
	 * Register console commands contributed by plugins via
	 * {@see \Quiote\Plugin\PluginRegistrar::command()}. Idempotent: safe to call
	 * again after a bootstrap has populated the registry (each command is only
	 * added once). Note the boundary in docs/PLUGIN_AND_EXTENSIBILITY_PLAN.md —
	 * `bin/quiote` builds this Application before any bootstrap, so plugin
	 * commands appear only once a bootstrap has run in the same process (e.g. a
	 * programmatic `new Application()` after `Quiote::bootstrap()`).
	 */
	public function addContributedCommands(): void
	{
		foreach (\Quiote\Plugin\PluginManager::contributedCommands() as $fqcn) {
			if (!class_exists($fqcn) || $this->has(self::commandName($fqcn))) {
				continue;
			}
			$command = new $fqcn();
			if ($command instanceof \Symfony\Component\Console\Command\Command) {
				$this->addCommand($command);
			}
		}
	}

	/** Best-effort resolution of a command's configured name from its #[AsCommand] attribute, for dedupe. */
	private static function commandName(string $fqcn): string
	{
		$attrs = (new \ReflectionClass($fqcn))->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class);
		if ($attrs) {
			$name = $attrs[0]->newInstance()->name;
			if (is_string($name) && $name !== '') {
				return $name;
			}
		}
		return $fqcn;
	}

	private static function version(): string
	{
		$versionFile = dirname(__DIR__) . '/version.php';
		if (is_file($versionFile)) {
			require_once $versionFile;
		}
		return \Quiote\Config\Config::get('quiote.version', 'dev');
	}
}
