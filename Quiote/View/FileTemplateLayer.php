<?php
namespace Quiote\View;

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Exception\QuioteException;
use Quiote\Util\Toolkit;

/**
 * Template layer implementation for templates fetched using a PHP stream.
 * @since      1.0.0
 * @version    1.0.0
 */
class FileTemplateLayer extends StreamTemplateLayer
{
	/**
	 * Per-worker cache of the expanded "directory" value, keyed by the raw
	 * directory pattern plus the scalar/null parameters expandVariables()
	 * interpolates. getResourceStreamIdentifier() ran its own array_filter +
	 * array_combine + expandVariables() pass on every call, entirely before
	 * delegating to the parent's own (already cached) resolution -- so the
	 * parent's cache never covered this work.
	 * @var        array<string, string>
	 */
	private static array $directoryCache = [];

	/**
	 * Constructor.
	 * @param      array<string, mixed> $parameters Initial parameters.
	 * @since      1.0.0
	 */
	public function __construct(array $parameters = [])
	{
		$targets = [];
		if(Config::getBool('core.use_translation', false)) {
			$targets[] = '${directory}/${locale}/${template}${extension}';
			$targets[] = '${directory}/${template}.${locale}${extension}';
		}
		$targets[] = '${directory}/${template}${extension}';

		parent::__construct(array_merge([
			'directory' => Config::getString('core.module_dir') . '/${module}/Templates',
			'scheme' => 'file',
			'check' => true,
			'targets' => $targets,
		], $parameters));
	}
	
	/**
	 * Initialize the layer.
	 * Will try and figure out an alternative default for "directory".
	 * @param      Context $context The current Context instance.
	 * @param      array<string, mixed> $parameters An array of initialization parameters.
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		$this->setParameter('directory', Toolkit::evaluateModuleDirective($parameters['module'] ?? '', 'quiote.template.directory'));
		
		parent::initialize($context, $parameters);
	}
	
	/**
	 * Get the full, resolved stream location name to the template resource.
	 * @return     ?string A PHP stream resource identifier, or null if no template is set.
	 * @throws     QuioteException If the template could not be found.
	 * @since      1.0.0
	 */
	#[\Override]
    public function getResourceStreamIdentifier()
	{
		$retval = null;
		$template = $this->getParameter('template');
		
		if($template === null) {
			// no template set, we return null so nothing gets rendered
			return null;
		} elseif(Toolkit::isPathAbsolute($template)) {
			// the template is an absolute path, ignore the dir
			$directory = dirname((string) $template);
			$template = basename((string) $template);
		} else {
			$directory = $this->getParameter('directory');
		}
		// treat the directory as sprintf format string and inject module name.
		// Parameter names are always strings in practice; rekey defensively since
		// ParameterHolder declares its storage as array<int|string, mixed>.
		$scalarParams = array_filter($this->getParameters(), is_scalar(...));
		$nullParams = array_filter($this->getParameters(), is_null(...));

		$cacheKey = is_scalar($directory) ? (string) $directory : '';
		foreach ($scalarParams as $k => $v) { $cacheKey .= "\0s:$k=$v"; }
		foreach ($nullParams as $k => $v) { $cacheKey .= "\0n:$k"; }

		if (isset(self::$directoryCache[$cacheKey])) {
			$directory = self::$directoryCache[$cacheKey];
		} else {
			$expandArgs = array_merge($scalarParams, $nullParams);
			$expandArgs = array_combine(array_map('strval', array_keys($expandArgs)), $expandArgs);
			$directory = Toolkit::expandVariables($directory, $expandArgs);
			self::$directoryCache[$cacheKey] = $directory;
		}
		
		$this->setParameter('directory', $directory);
		$this->setParameter('template', $template);
		if(!$this->hasParameter('extension')) {
			$renderer = $this->getRenderer();
			if($renderer === null) {
				throw new QuioteException('Template layer has no renderer set: cannot determine the default extension.');
			}
			$this->setParameter('extension', $renderer->getDefaultExtension());
		}
		
		// everything set up for the parent
		return parent::getResourceStreamIdentifier();
	}
}

?>