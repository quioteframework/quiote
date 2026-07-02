<?php
declare(strict_types=1);

namespace Quiote\Console\Command;

use Quiote\Config\Config;
use Quiote\Exception\QuioteException;
use Quiote\Quiote;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Base for commands that need a bootstrapped Quiote application (as opposed
 * to `new`, which is deliberately pre-bootstrap -- see NewCommand). Adds the
 * `--app-dir`/`--env` options and the app-dir resolution + Quiote::bootstrap()
 * wiring described in docs/ROUTING_AND_CLI_PLAN.md (B1).
 *
 * App-dir resolution order: `--app-dir`, else $QUIOTE_APP_DIR, else an
 * upward search from the current directory for a `Config/settings.*` file.
 * If `core.app_dir` is already set (e.g. a test harness bootstrapped it
 * before invoking the command via CommandTester), that value wins and no
 * resolution/re-bootstrap of app-dir happens -- only the environment is
 * (re-)applied, which Quiote::bootstrap() already treats idempotently.
 * @since      1.0.0
 */
abstract class AbstractAppCommand extends Command
{
	private const SETTINGS_EXTENSIONS = ['php', 'xml', 'yaml', 'yml'];

	protected function configureAppOptions(): void
	{
		$this
			->addOption('app-dir', null, InputOption::VALUE_REQUIRED, 'Path to the application directory (defaults to $QUIOTE_APP_DIR, else an upward search from the current directory)')
			->addOption('env', null, InputOption::VALUE_REQUIRED, 'Environment to bootstrap (defaults to $QUIOTE_ENV, else "development")');
	}

	protected function bootstrapApp(InputInterface $input): void
	{
		if (!Config::has('core.app_dir')) {
			Config::set('core.app_dir', $this->resolveAppDir($input), true, true);
		}

		$env = $input->getOption('env') ?: (getenv('QUIOTE_ENV') ?: 'development');
		Quiote::bootstrap($env);

		$this->registerAppNamespaceFallbackAutoloader();
	}

	/**
	 * `quiote new`-scaffolded apps are self-contained: they have no
	 * composer.json of their own and rely on a PSR-4-ish autoloader for
	 * their own namespace registered only inside their generated
	 * pub/index.php (see AppWriter::frontControllerPhp()). A command running
	 * outside that front controller -- e.g. `routes:list` resolving the
	 * app's Routing class via Context -- would otherwise fail to autoload
	 * app classes entirely. Mirror that same {namespace}\ -> {app_dir}/
	 * mapping here as a fallback; for composer-installed apps the "real"
	 * autoloader already registered by vendor/autoload.php resolves the
	 * class first and this is simply never reached for it.
	 */
	private function registerAppNamespaceFallbackAutoloader(): void
	{
		$namespacePrefix = trim((string) Config::get('core.namespace_prefix', 'App'), '\\');
		$appDir = Config::get('core.app_dir');

		spl_autoload_register(static function (string $class) use ($namespacePrefix, $appDir): void {
			$prefix = $namespacePrefix . '\\';
			if (!str_starts_with($class, $prefix)) {
				return;
			}
			$relative = substr($class, strlen($prefix));
			$file = $appDir . '/' . str_replace('\\', '/', $relative) . '.php';
			if (is_file($file)) {
				require $file;
			}
		});
	}

	private function resolveAppDir(InputInterface $input): string
	{
		$appDir = $input->getOption('app-dir') ?: (getenv('QUIOTE_APP_DIR') ?: null);
		if ($appDir !== null) {
			$real = realpath($appDir);
			if ($real === false || !is_dir($real)) {
				throw new QuioteException(sprintf('App directory "%s" does not exist.', $appDir));
			}
			return $real;
		}

		$dir = getcwd();
		while ($dir !== false && $dir !== '') {
			if ($this->hasSettingsFile($dir)) {
				return $dir;
			}
			$parent = dirname($dir);
			if ($parent === $dir) {
				break;
			}
			$dir = $parent;
		}

		throw new QuioteException('Could not locate a Quiote application. Pass --app-dir, set $QUIOTE_APP_DIR, or run this command from inside an application directory (one containing Config/settings.*).');
	}

	private function hasSettingsFile(string $dir): bool
	{
		foreach (self::SETTINGS_EXTENSIONS as $extension) {
			if (is_file($dir . '/Config/settings.' . $extension)) {
				return true;
			}
		}
		return false;
	}
}
