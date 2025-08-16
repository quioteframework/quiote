<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi;

use Agavi\Config\AgaviConfig;
use Agavi\Config\AgaviConfigCache;
use Agavi\Config\AgaviAPCuConfigCache;
use Agavi\Exception\AgaviException;
use Agavi\Util\AgaviToolkit;

// check minimum PHP version
AgaviConfig::set('core.minimum_php_version', '8.4.0');
if(version_compare(PHP_VERSION, AgaviConfig::get('core.minimum_php_version'), '<') ) {
	trigger_error('Agavi requires PHP version ' . AgaviConfig::get('core.minimum_php_version') . ' or greater', E_USER_ERROR);
}

// define a few filesystem paths
AgaviConfig::set('core.agavi_dir', $agavi_config_directive_core_agavi_dir = __DIR__, true, true);

// default exception template
AgaviConfig::set('exception.default_template', $agavi_config_directive_core_agavi_dir . '/Exception/templates/shiny.php');

// required files
require_once($agavi_config_directive_core_agavi_dir . '/version.php');
// clean up (we don't want collisions with whatever file included us, in case you were wondering about the ugly name of that var)
unset($agavi_config_directive_core_agavi_dir);


/**
 * Main framework class used for autoloading and initial bootstrapping of Agavi.
 *
 * @package    agavi
 * @subpackage core
 *
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
final class Agavi
{
	/**
	 * Startup the Agavi core
	 *
	 * @param      string environment the environment to use for this session.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	/**
	 * Bootstrap the Agavi core (environment + optional context pre-initialization).
	 *
	 * Backward compatible signature extension:
	 *  - Previous: bootstrap('env')
	 *  - New:      bootstrap('env', 'web') or bootstrap('env', ['web','api'], ['prewarm' => true])
	 *
	 * @param string|null               $environment Environment name (core.environment)
	 * @param string|array|null         $contexts    One or multiple context names to pre-create
	 * @param array                     $options     ['prewarm' => bool] force prewarm
	 * @return array{contexts: array<string,\Agavi\AgaviContext>} Context map (may be empty)
	 */
	public static function bootstrap($environment = null, $contexts = null, array $options = [])
	{

		try {
			if($environment === null) {
				// no env given? let's read one from core.environment
				$environment = AgaviConfig::get('core.environment');
			} elseif(AgaviConfig::has('core.environment') && AgaviConfig::isReadonly('core.environment')) {
				// env given, but core.environment is read-only? then we must use that instead and ignore the given setting
				$environment = AgaviConfig::get('core.environment');
			}
			
			if($environment === null) {
				// still no env? oh man...
				throw new AgaviException('You must supply an environment name to Agavi::bootstrap() or set the name of the default environment to be used in the configuration directive "core.environment".');
			}
			
			// finally set the env to what we're really using now.
			AgaviConfig::set('core.environment', $environment, true, true);

			AgaviConfig::set('core.debug', false, false);

			if(!AgaviConfig::has('core.app_dir')) {
				throw new AgaviException('Configuration directive "core.app_dir" not defined, terminating...');
			}

			// define a few filesystem paths
			AgaviConfig::set('core.cache_dir', AgaviConfig::get('core.app_dir') . '/cache', false, true);

			AgaviConfig::set('core.config_dir', AgaviConfig::get('core.app_dir') . '/Config', false, true);

			AgaviConfig::set('core.system_config_dir', AgaviConfig::get('core.agavi_dir') . '/Config/defaults', false, true);

			AgaviConfig::set('core.lib_dir', AgaviConfig::get('core.app_dir') . '/Lib', false, true);

			AgaviConfig::set('core.model_dir', AgaviConfig::get('core.app_dir') . '/Models', false, true);

			AgaviConfig::set('core.module_dir', AgaviConfig::get('core.app_dir') . '/Modules', false, true);

			AgaviConfig::set('core.template_dir', AgaviConfig::get('core.app_dir') . '/Templates', false, true);
			AgaviConfig::set('core.cldr_dir', AgaviConfig::get('core.agavi_dir') . '/Translation/data', false, true);

			// load base settings
			if(defined('\AGAVI_USE_APCU_CONFIG_CACHE') && \AGAVI_USE_APCU_CONFIG_CACHE) {
				AgaviAPCuConfigCache::load(AgaviConfig::get('core.config_dir') . '/settings.xml');
			} else {
				AgaviConfigCache::load(AgaviConfig::get('core.config_dir') . '/settings.xml');
			}

			// clear our cache if the conditions are right
			if(AgaviConfig::get('core.debug')) {
				AgaviToolkit::clearCache();

				// load base settings
				if(defined('AGAVI_USE_APCU_CONFIG_CACHE') && AGAVI_USE_APCU_CONFIG_CACHE) {
					AgaviAPCuConfigCache::load(AgaviConfig::get('core.config_dir') . '/settings.xml');
				} else {
					AgaviConfigCache::load(AgaviConfig::get('core.config_dir') . '/settings.xml');
				}
			}

			// compile.xml aggregation removed; rely on autoload + opcache.


			// Normalize contexts argument
			$contextList = [];
			if($contexts !== null) {
				if(is_string($contexts)) {
					$contextList = [$contexts];
				} elseif(is_array($contexts)) {
					$contextList = $contexts;
				}
			}
			// If no contexts explicitly provided, we can still prewarm default if requested.
			$createdContexts = [];
			foreach($contextList as $ctxName) {
				$ctxName = strtolower($ctxName);
				$ctx = \Agavi\AgaviContext::getInstance($ctxName);
				$createdContexts[$ctxName] = $ctx;
				// Prime controller & output types (forces factories + output_types.xml load)
				try {
					$controller = $ctx->getController();
					if(method_exists($controller, 'startup')) {
						$controller->startup();
					}
					// Touch default output type to ensure it's in-memory
					$controller->getOutputType();
				} catch(\Throwable $e) {
					$ctxLogger = $ctx->getLoggerManager()?->getLogger();
					if($ctxLogger) { $ctxLogger->debug('bootstrap controller prime failed for context ' . $ctxName . ': ' . $e->getMessage()); }
				}
			}

			// Decide prewarm
			$doPrewarm = $options['prewarm'] ?? false;
			if(!$doPrewarm) {
				if(defined('AGAVI_USE_APCU_CONFIG_CACHE') && AGAVI_USE_APCU_CONFIG_CACHE) {
					$envPrewarm = getenv('AGAVI_APCU_PREWARM');
					if($envPrewarm !== false && in_array(strtolower($envPrewarm), ['1','true','yes','on'], true)) {
						$doPrewarm = true;
					}
					if(AgaviConfig::has('core.apcu_prewarm') && AgaviConfig::get('core.apcu_prewarm')) {
						$doPrewarm = true;
					}
				}
			}
			if($doPrewarm && defined('AGAVI_USE_APCU_CONFIG_CACHE') && AGAVI_USE_APCU_CONFIG_CACHE) {
				// Prewarm for each explicit context, or default context if none provided
				if($createdContexts) {
					foreach(array_keys($createdContexts) as $c) {
						self::prewarm($c);
					}
				} else {
					self::prewarm(AgaviConfig::get('core.default_context'));
				}
			}

			return ['contexts' => $createdContexts];

		} catch(\Exception $e) {
			AgaviException::render($e);
		}
	}

	/**
	 * Prewarm APCu configuration and translation caches to avoid first-request latency.
	 * Safe to call multiple times (idempotent-ish). Only active when APCu config cache is enabled.
	 *
	 * @param string|null $context Context name for context-specific caches (routing, factories)
	 */
	public static function prewarm(?string $context = null): void
	{
		if(!(defined('\AGAVI_USE_APCU_CONFIG_CACHE') && \AGAVI_USE_APCU_CONFIG_CACHE)) {
			return; // feature disabled
		}
		if(!class_exists(AgaviAPCuConfigCache::class)) {
			return;
		}
		if(!AgaviAPCuConfigCache::isAvailable()) {
			return;
		}
		try {
			// Warm core config/routing/databases/logging/etc.
			AgaviAPCuConfigCache::warmup([], $context);
			// Translation supplemental + timezone data (lightweight, no context)
			if(AgaviConfig::get('core.use_translation', false)) {
				$cldrDir = AgaviConfig::get('core.cldr_dir');
				if($cldrDir && is_dir($cldrDir)) {
					$suppFile = $cldrDir . '/supplementalData.xml';
					if(is_readable($suppFile)) {
						$suppData = include(AgaviAPCuConfigCache::checkConfig($suppFile));
						if(function_exists('apcu_store')) { apcu_store('agavi_i18n_supplemental', $suppData, 0); }
					}
					$tzFile = $cldrDir . '/timezones/zonelist.php';
					if(is_readable($tzFile)) {
						$tzList = include($tzFile);
						if(function_exists('apcu_store')) { apcu_store('agavi_i18n_tzlist', $tzList, 0); }
					}
				}
			}
			// Record status metadata fetch (optional touch)
			AgaviAPCuConfigCache::getStatus();
		} catch(\Throwable $e) {
			// Swallow errors; prewarm is opportunistic
			$logger = \Agavi\AgaviContext::getInstance(AgaviConfig::get('core.default_context'))?->getLoggerManager()?->getLogger();
			if($logger) { $logger->warning('prewarm failed: '.$e->getMessage()); }
		}
	}

	/**
	 * Retrieve (and optionally prime) a context instance.
	 * Convenience wrapper so worker front controllers can call
	 *   $ctx = Agavi::context('web', true);
	 * instead of manual AgaviContext::getInstance + priming logic.
	 *
	 * @param string|null $name   Context name (defaults to core.default_context)
	 * @param bool        $prime  Prime controller/output types immediately
	 * @return AgaviContext
	 */
	public static function context(?string $name = null, bool $prime = false): AgaviContext
	{
		$ctx = AgaviContext::getInstance($name);
		if($prime) {
			try {
				$controller = $ctx->getController();
				if(method_exists($controller, 'startup')) {
					$controller->startup();
				}
				$controller->getOutputType();
			} catch(\Throwable $e) {
				$ctxLogger = $ctx->getLoggerManager()?->getLogger();
				if($ctxLogger) { $ctxLogger->debug('context prime failed: '.$e->getMessage()); }
			}
		}
		return $ctx;
	}
}

?>