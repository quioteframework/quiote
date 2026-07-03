<?php
namespace Quiote\Console;

use Quiote\Console\Command\AboutCommand;
use Quiote\Console\Command\CacheWarmupCommand;
use Quiote\Console\Command\NewCommand;
use Quiote\Console\Command\RoutesListCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * The `quiote` CLI. `new` is pre-bootstrap (it scaffolds an app from
 * nothing, so there is no Quiote\Context to build yet); `about` and
 * `routes:list` bootstrap an existing app via AbstractAppCommand's app-dir
 * resolution + Quiote::bootstrap() wiring -- see
 * docs/ROUTING_AND_CLI_PLAN.md (B1-B3).
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
