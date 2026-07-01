<?php
namespace Quiote\Renderer;
/**
 * A renderer produces the output as defined by a View
 * @since      1.0.0
 * @version    1.0.0
 */
class PhptalRenderer extends Renderer
{
	/**
	 * @constant   string The directory inside the cache dir where templates will
	 *                    be stored in compiled form.
	 */
	const COMPILE_DIR = 'templates';
	
	/**
	 * @constant   string The subdirectory inside the compile dir where templates
	 *                    will be stored in compiled form.
	 */
	const COMPILE_SUBDIR = 'phptal';
	
	/**
	 * @var        string A string with the default template file extension,
	 *                    including the dot.
	 */
	protected $defaultExtension = '.tal';

	/**
	 * @var        PHPTAL PHPTAL template engine.
	 */
	protected $phptal = null;

	/**
	 * Pre-serialization callback.
	 * Excludes the PHPTAL instance to prevent excessive serialization load.
	 * @since      1.0.0
	 */
	#[\Override]
    public function __sleep()
	{
		$keys = parent::__sleep();
		unset($keys[array_search('phptal', $keys)]);
		return $keys;
	}
	
	/**
	 * Create an instance of PHPTAL and initialize it correctly.
	 * @return     PHPTAL The PHPTAL instance.
	 * @since      1.0.0
	 */
	protected function createEngineInstance()
	{
		$phptalPhpCodeDestination = Config::get('core.cache_dir') . DIRECTORY_SEPARATOR . PhptalRenderer::COMPILE_DIR . DIRECTORY_SEPARATOR . PhptalRenderer::COMPILE_SUBDIR . DIRECTORY_SEPARATOR;
		
		// we keep this for < 1.2
		if(!defined('PHPTAL_PHP_CODE_DESTINATION')) {
			define('PHPTAL_PHP_CODE_DESTINATION', $phptalPhpCodeDestination);
		}
		
		Toolkit::mkdir($phptalPhpCodeDestination, fileperms(Config::get('core.cache_dir')), true);
		
		if(!class_exists('PHPTAL')) {
			require('PHPTAL.php');
		}
		
		$phptal = new PHPTAL();
		
		if(version_compare(PHPTAL_VERSION, '1.2', 'ge')) {
			$phptal->setPhpCodeDestination($phptalPhpCodeDestination);
		} else {
		  trigger_error('Support for PHPTAL versions older than 1.2 is deprecated and will be removed in Quiote 1.2.', E_USER_DEPRECATED);
		}
		
		if($this->hasParameter('encoding')) {
			$phptal->setEncoding($this->getParameter('encoding'));
		}
		
		return $phptal;
	}

	/**
	 * Retrieve the PHPTAL instance
	 * @return     PHPTAL A PHPTAL instance.
	 * @since      1.0.0
	 */
	protected function getEngine()
	{
		if($this->phptal) {
			return $this->phptal;
		}
		
		$this->phptal = $this->createEngineInstance();
		
		return $this->phptal;
	}

	/**
	 * Render the presentation and return the result.
	 * @param      TemplateLayer The template layer to render.
	 * @param      array              The template variables.
	 * @param      array              The slots.
	 * @param      array              Associative array of additional assigns.
	 * @return     string A rendered result.
	 * @since      1.0.0
	 */
	public function render(TemplateLayer $layer, array &$attributes = [], array &$slots = [], array &$moreAssigns = [])
	{
		$engine = $this->getEngine();
		
		if($this->extractVars) {
			foreach($attributes as $key => $value) {
				$engine->set($key, $value);
			}
		} else {
			$engine->set($this->varName, $attributes);
		}
		
		$engine->set($this->slotsVarName, $slots);
		
		foreach($this->assigns as $key => $getter) {
			$engine->set($key, $this->context->$getter());
		}
		
		$finalMoreAssigns =& self::buildMoreAssigns($moreAssigns, $this->moreAssignNames);
		foreach($finalMoreAssigns as $key => &$value) {
			$engine->set($key, $value);
		}
		
		$engine->setTemplate($layer->getResourceStreamIdentifier());
		
		return $engine->execute();
	}
}

?>