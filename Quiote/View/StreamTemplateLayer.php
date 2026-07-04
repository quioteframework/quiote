<?php
namespace Quiote\View;

use Quiote\Config\Config;
use Quiote\Exception\QuioteException;
use Quiote\Translation\QuioteLocale;
use Quiote\Util\Toolkit;

/**
 * Template layer implementation for templates fetched using a PHP stream.
 * @since      1.0.0
 * @version    1.0.0
 */
class StreamTemplateLayer extends TemplateLayer
{
	/**
	 * Resolved-path cache, shared across all layer instances for the process
	 * lifetime. Template layers are rebuilt per render (View::createLayer does
	 * `new $class()` each time), so an instance cache would never hit; the
	 * resolution — which target pattern exists on disk for a given set of
	 * parameters — is constant for a worker's lifetime, so we key a static
	 * cache on those inputs and skip the per-render expandVariables +
	 * is_readable filesystem probes. Only successful resolutions are cached.
	 * @var array<string,string>
	 */
	private static array $resolvedCache = [];

	/**
	 * Constructor.
	 * @param      array $parameters Initial parameters.
	 * @since      1.0.0
	 */
	public function __construct(array $parameters = [])
	{
		parent::__construct(array_merge([
			'check' => false,
			'scheme' => null,
			'targets' => [
				'${template}',
			],
		], $parameters));
	}
	
	/**
	 * Get the full, resolved stream location name to the template resource.
	 * @return     ?string A PHP stream resource identifier, or null if no template is set.
	 * @throws     QuioteException If the template could not be found.
	 * @since      1.0.0
	 */
	public function getResourceStreamIdentifier()
	{
		$template = $this->getParameter('template');
		
		if($template === null) {
			// no template set, we return null so nothing gets rendered
			return null;
		}
		
		$args = [];
		if(Config::get('core.use_translation')) {
			// i18n is enabled, build a list of sprintf args with the locale identifier
			foreach(QuioteLocale::getLookupPath($this->context->getTranslationManager()->getCurrentLocaleIdentifier()) as $identifier) {
				$args[] = ['locale' => $identifier];
			}
		}
		
		if(empty($args)) {
			$args[] = []; // add one empty arg to always trigger target lookups (even if i18n is disabled etc.)
		}
		
		$scheme = $this->getParameter('scheme');
		// FIXME: a simple workaround for broken ubuntu and debian packages (fixed already), we can remove that for final 0.11
		if($scheme != 'file' && !in_array($scheme, stream_get_wrappers())) {
			throw new QuioteException('Unknown stream wrapper "' . $scheme . '", must be one of "' . implode('", "', stream_get_wrappers()) . '".');
		}
		$check = $this->getParameter('check');

		$targets = (array)$this->getParameter('targets', []);
		$scalarParams = array_filter($this->getParameters(), is_scalar(...));
		$nullParams = array_filter($this->getParameters(), is_null(...));

		// Key the resolution on everything that feeds it: scheme, the target
		// patterns, the scalar/null parameters expandVariables interpolates, and
		// the locale lookup path. Constant per worker, so a hit skips both
		// expandVariables and the is_readable probes below.
		$cacheKey = $scheme . "\0" . implode("\0", $targets);
		foreach($scalarParams as $k => $v) { $cacheKey .= "\0s:$k=$v"; }
		foreach($nullParams as $k => $v) { $cacheKey .= "\0n:$k"; }
		foreach($args as $arg) { foreach($arg as $k => $v) { $cacheKey .= "\0a:$k=$v"; } }
		if(isset(self::$resolvedCache[$cacheKey])) {
			return self::$resolvedCache[$cacheKey];
		}

		$attempts = [];

		// try each of the patterns
		foreach($targets as $pattern) {
			// try pattern with each argument list
			foreach($args as $arg) {
				$target = Toolkit::expandVariables($pattern, array_merge($scalarParams, $nullParams, $arg));
				// FIXME (should they fix it): don't add file:// because suhosin's include whitelist is empty by default, does not contain 'file' as allowed uri scheme
				if($scheme != 'file') {
					$target = $scheme . '://' . $target;
				}
				if(!$check || is_readable($target)) {
					return self::$resolvedCache[$cacheKey] = $target;
				}
				$attempts[] = $target;
			}
		}

		// no template found, time to throw an exception
		throw new QuioteException('Template "' . $template . '" could not be found. Paths tried:' . "\n" . implode("\n", $attempts));
	}
}

?>
