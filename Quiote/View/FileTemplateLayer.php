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
		// treat the directory as sprintf format string and inject module name
		$directory = Toolkit::expandVariables($directory, array_merge(array_filter($this->getParameters(), is_scalar(...)), array_filter($this->getParameters(), is_null(...))));
		
		$this->setParameter('directory', $directory);
		$this->setParameter('template', $template);
		if(!$this->hasParameter('extension')) {
			$this->setParameter('extension', $this->renderer->getDefaultExtension());
		}
		
		// everything set up for the parent
		return parent::getResourceStreamIdentifier();
	}
}

?>