<?php
namespace Quiote\Console;

use Quiote\Console\Command\NewCommand;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * The `quiote` CLI. Deliberately thin for now: `new` is the only command and
 * it is pre-bootstrap (it scaffolds an app from nothing, so there is no
 * Quiote\Context to build yet). Commands that need to inspect an existing
 * app's config (routes:list, routes:compile, ...) belong to a later phase
 * and will need the app-dir/env resolution + Quiote::bootstrap('console')
 * wiring described in docs/ROUTING_AND_CLI_PLAN.md -- not needed here.
 * @since      1.0.0
 */
final class Application extends SymfonyApplication
{
	public function __construct()
	{
		parent::__construct('quiote', self::version());
		$this->addCommand(new NewCommand());
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
