<?php
namespace Quiote;

use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Config\APCuConfigCache;
use Quiote\Exception\QuioteException;
use Quiote\Util\Toolkit;

// check minimum PHP version
Config::set('core.minimum_php_version', '8.5.0');
if(version_compare(PHP_VERSION, Config::get('core.minimum_php_version'), '<') ) {
	trigger_error('Quiote requires PHP version ' . Config::get('core.minimum_php_version') . ' or greater', E_USER_ERROR);
}

// define a few filesystem paths
Config::set('core.quiote_dir', $quiote_config_directive_core_quiote_dir = __DIR__, true, true);

// required files
require_once($quiote_config_directive_core_quiote_dir . '/version.php');
// clean up (we don't want collisions with whatever file included us, in case you were wondering about the ugly name of that var)
unset($quiote_config_directive_core_quiote_dir);


/**
 * Main framework class used for autoloading and initial bootstrapping of Quiote.
 * @since      1.0.0
 * @version    1.0.0
 */
final class Quiote
{
	/**
     * Startup the Quiote core
     * @param      string environment the environment to use for this session.
     * @since      1.0.0
     */
    /**
     * Bootstrap the Quiote core (environment + optional context pre-initialization).
     * Backward compatible signature extension:
     *  - Previous: bootstrap('env')
     *  - New:      bootstrap('env', 'web') or bootstrap('env', ['web','api'], ['prewarm' => true])
     * @param string|null               $environment Environment name (core.environment)
     * @param string|array|null         $contexts    One or multiple context names to pre-create
     * @param array                     $options     ['prewarm' => bool] force prewarm
     * @return array{contexts: array<string, \Quiote\Context>} Context map (may be empty)
     */
    public static function bootstrap($environment = null, $contexts = null, array $options = [])
	{

		try {
			if($environment === null) {
				// no env given? let's read one from core.environment
				$environment = Config::get('core.environment');
			} elseif(Config::has('core.environment') && Config::isReadonly('core.environment')) {
				// env given, but core.environment is read-only? then we must use that instead and ignore the given setting
				$environment = Config::get('core.environment');
			}

			if($environment === null) {
				// still no env? oh man...
				throw new QuioteException('You must supply an environment name to Quiote::bootstrap() or set the name of the default environment to be used in the configuration directive "core.environment".');
			}

			// finally set the env to what we're really using now.
			Config::set('core.environment', $environment, true, true);

			Config::set('core.debug', false, false);

			// Standalone from core.debug: no shared "production mode", no
			// implicit derivation. Off by default -- these are separate
			// switches (core.debug carries unrelated, heavy per-request
			// behavior; this only controls exception response detail).
			Config::set('core.developer_exceptions', false, false);

			if(!Config::has('core.app_dir')) {
				throw new QuioteException('Configuration directive "core.app_dir" not defined, terminating...');
			}

			// define a few filesystem paths
			Config::set('core.cache_dir', Config::get('core.app_dir') . '/cache', false, true);

			Config::set('core.config_dir', Config::get('core.app_dir') . '/Config', false, true);

			Config::set('core.system_config_dir', Config::get('core.quiote_dir') . '/Config/defaults', false, true);

			Config::set('core.lib_dir', Config::get('core.app_dir') . '/Lib', false, true);

			Config::set('core.model_dir', Config::get('core.app_dir') . '/Models', false, true);

			Config::set('core.module_dir', Config::get('core.app_dir') . '/Modules', false, true);

			Config::set('core.template_dir', Config::get('core.app_dir') . '/Templates', false, true);

			// load base settings
			if(defined('\QUIOTE_USE_APCU_CONFIG_CACHE') && \QUIOTE_USE_APCU_CONFIG_CACHE) {
				APCuConfigCache::load(Config::get('core.config_dir') . '/settings.xml');
			} else {
				ConfigCache::load(Config::get('core.config_dir') . '/settings.xml');
			}

			// clear our cache if the conditions are right
			if(Config::get('core.debug')) {
				Toolkit::clearCache();

				// load base settings
				if(defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE) {
					APCuConfigCache::load(Config::get('core.config_dir') . '/settings.xml');
				} else {
					ConfigCache::load(Config::get('core.config_dir') . '/settings.xml');
				}
			}

			// compile.xml aggregation removed; rely on autoload + opcache.

			// Plugin registration lives exactly here — after settings have loaded
			// (so app config wins over plugin defaults) and before any Context is
			// created (so plugins can contribute config/services/middleware/routes
			// the contexts will consume).
			//
			// telemetry-otel (Quiote\Telemetry\TelemetryPlugin) and whoops
			// (Quiote\Exception\Rendering\Whoops\WhoopsPlugin) are NOT
			// registered here — both are fully opt-in via the `plugins` config
			// key, exactly like Quiote\Mcp\McpPlugin: neither was ever
			// default/always-on behavior in a way that would break an existing
			// app if it stayed absent.
			\Quiote\Plugin\PluginManager::bootFromConfig();

			// CSRF is the one exception: a deliberate opt-OUT default, not
			// opt-in. It's a security default, not merely a packaging
			// convenience -- physically living in its own package
			// (`packages/csrf/`) is a code-
			// organization choice, but the framework always pulls the package
			// in (`quioteframework/csrf` is in composer.json's `require`, not
			// `require-dev`/`suggest`) and always registers it here, so a
			// fresh app is protected without having to know to ask for it.
			// Disabling it takes conscious effort: set `core.csrf.enabled` to
			// `false` (CsrfManager::isEnabled(), already the runtime gate both
			// CSRF middleware check), or the harder `composer remove
			// quioteframework/csrf`, which this class_exists() guard degrades
			// gracefully from (no CSRF, not a fatal error) rather than assuming
			// the package can never be absent.
			if (class_exists(\Quiote\Security\Csrf\CsrfPlugin::class)) {
				(new \Quiote\Security\Csrf\CsrfPlugin())->register(
					new \Quiote\Plugin\PluginRegistrar('quiote/csrf')
				);
				if (!Config::get('core.csrf.enabled', true)) {
					\Quiote\Logging\Log::create('Quiote.Quiote')->warning(
						'[Quiote::bootstrap] CSRF protection is explicitly disabled (core.csrf.enabled=false). '
						. 'This is a deliberate "conscious effort" opt-out -- '
						. 'confirm that is intentional.'
					);
				}
			} else {
				\Quiote\Logging\Log::create('Quiote.Quiote')->warning(
					'[Quiote::bootstrap] CSRF protection is unavailable: quioteframework/csrf is not installed. '
					. 'Every app is expected to have it (it is a mandatory kernel dependency); if you removed it '
					. 'deliberately, this warning is expected.'
				);
			}

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
				$ctxName = strtolower((string) $ctxName);
				$ctx = \Quiote\Context::getInstance($ctxName);
				$createdContexts[$ctxName] = $ctx;
				// Prime controller & output types (forces factories + output_types.xml load)
				try {
					$controller = $ctx->getController();
					$controller->startup();
					// Touch default output type to ensure it's in-memory
					$controller->getOutputType();
				} catch(\Throwable $e) {
					\Quiote\Logging\Log::create('Quiote.Quiote')->debug('bootstrap controller prime failed for context ' . $ctxName . ': ' . $e->getMessage());
				}
			}

			// Decide prewarm
			$doPrewarm = $options['prewarm'] ?? false;
			if(!$doPrewarm) {
				if(defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE) {
					$envPrewarm = getenv('QUIOTE_APCU_PREWARM');
					if($envPrewarm !== false && in_array(strtolower($envPrewarm), ['1','true','yes','on'], true)) {
						$doPrewarm = true;
					}
					if(Config::has('core.apcu_prewarm') && Config::get('core.apcu_prewarm')) {
						$doPrewarm = true;
					}
				}
			}
			if($doPrewarm && defined('QUIOTE_USE_APCU_CONFIG_CACHE') && QUIOTE_USE_APCU_CONFIG_CACHE) {
				// Prewarm for each explicit context, or default context if none provided
				if($createdContexts) {
					foreach(array_keys($createdContexts) as $c) {
						self::prewarm($c);
					}
				} else {
					self::prewarm(Config::get('core.default_context'));
				}
			}

			// Whole-framework "we're up" hook: settings loaded, plugins registered,
			// requested contexts created.
			// The real telemetry provider (if any) is built by a
			// Quiote\Telemetry\TelemetryPlugin listener on this exact event,
			// so TraceRegistry::hasRealProvider() only reflects reality once
			// this has fired -- check after, not before.
			\Quiote\Event\Events::emit(new \Quiote\Event\Lifecycle\KernelBootEvent(
				(string) Config::get('core.environment'),
				$createdContexts,
			));

			if (Config::get('telemetry.enabled', false) && !\Quiote\Telemetry\TraceRegistry::hasRealProvider()) {
				\Quiote\Logging\Log::create('Quiote.Quiote')->warning(
					'[Quiote::bootstrap] telemetry.enabled is true but no real telemetry provider is active. '
					. 'telemetry-otel is opt-in: install '
					. 'quioteframework/telemetry-otel and add Quiote\\Telemetry\\TelemetryPlugin to the '
					. '`plugins` config key, or set telemetry.enabled=false to silence this.'
				);
			}

			return ['contexts' => $createdContexts];

		} catch(\Exception $e) {
			// Same reasoning as Context::getInstance()/initialize(): bootstrap
			// failures happen before any PSR-15 pipeline exists, so there is no
			// ErrorHandlingMiddleware to hand off to yet. Log and propagate
			// instead of rendering a template and exit()ing, which would kill a
			// persistent worker process outright.
			\Quiote\Logging\Log::for(self::class)->error(
				'Quiote::bootstrap() failed: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
			);
			throw $e;
		}
	}

	/**
	 * Prewarm APCu configuration and translation caches to avoid first-request latency.
	 * Safe to call multiple times (idempotent-ish). Only active when APCu config cache is enabled.
	 * @param string|null $context Context name for context-specific caches (routing, factories)
	 */
	public static function prewarm(?string $context = null): void
	{
		if(!(defined('\QUIOTE_USE_APCU_CONFIG_CACHE') && \QUIOTE_USE_APCU_CONFIG_CACHE)) {
			return; // feature disabled
		}
		if(!class_exists(APCuConfigCache::class)) {
			return;
		}
		if(!APCuConfigCache::isAvailable()) {
			return;
		}
		try {
			// Warm core config/routing/databases/logging/etc.
			APCuConfigCache::warmup([], $context);
			// Legacy CLDR supplemental + timezone prewarm removed (intl extension now authoritative)
			// Record status metadata fetch (optional touch)
			APCuConfigCache::getStatus();
		} catch(\Throwable $e) {
			// Swallow errors; prewarm is opportunistic
			\Quiote\Logging\Log::create('Quiote.Quiote')->warning('prewarm failed: '.$e->getMessage());
		}
	}

	/**
	 * Retrieve (and optionally prime) a context instance.
	 * Convenience wrapper so worker front controllers can call
	 *   $ctx = Quiote::context('web', true);
	 * instead of manual Context::getInstance + priming logic.
	 * @param string|null $name   Context name (defaults to core.default_context)
	 * @param bool        $prime  Prime controller/output types immediately
	 * @return Context
	 */
	public static function context(?string $name = null, bool $prime = false): Context
	{
		$ctx = Context::getInstance($name);
		if($prime) {
			try {
				$controller = $ctx->getController();
				$controller->startup();
				$controller->getOutputType();
			} catch(\Throwable $e) {
				\Quiote\Logging\Log::create('Quiote.Quiote')->debug('context prime failed: '.$e->getMessage());
			}
		}
		return $ctx;
	}
}

?>
