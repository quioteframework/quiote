<?php
namespace Quiote\Testing;

use PHPUnit\TextUI\Command;


/**
 * Main framework class used for running tests on the command line interface.
 * @since      1.0.0
 */
class PhpUnitCli extends Command
{
	
	/**
	 * @since      1.0.0
	 */
	public function __construct()
	{
		$this->longOptions['environment='] = 'handleEnvironment';
		$this->longOptions['include-suite='] = 'handleIncludeSuite';
		$this->longOptions['exclude-suite='] = 'handleExcludeSuite';
		$this->longOptions['no-expand-configuration'] = 'handleNoExpandConfiguration';
		
		$this->arguments['quioteEnvironment'] = !empty($_SERVER['QUIOTE_ENVIRONMENT']) ? $_SERVER['QUIOTE_ENVIRONMENT'] : 'testing';
		$this->arguments['quioteIncludeSuites'] = [];
		$this->arguments['quioteExcludeSuites'] = [];
		$this->arguments['quioteExpandConfiguration'] = true;
	}

	/**
	 * Callback handling the --environment command line option.
	 * @param      string The Quiote environment name.
	 * @since      1.0.0
	 */
	protected function handleEnvironment($value)
	{
		$this->arguments['quioteEnvironment'] = $value;
	}
	
	/**
	 * Callback handling the --include-suite command line option.
	 * @param      string The suite names, separated by comma, to include.
	 * @since      1.0.0
	 */
	protected function handleIncludeSuite($value)
	{
		$this->arguments['quioteIncludeSuites'] = array_merge(
			$this->arguments['quioteIncludeSuites'],
			explode(',', (string) $value)
		);
	}
	
	/**
	 * Callback handling the --exclude-suite command line option.
	 * @param      string The suite names, separated by comma, to exclude.
	 * @since      1.0.0
	 */
	protected function handleExcludeSuite($value)
	{
		$this->arguments['quioteExcludeSuites'] = array_merge(
			$this->arguments['quioteExcludeSuites'],
			explode(',', (string) $value)
		);
	}
	
	/**
	 * Callback handling the --no-expand-configuration command line option.
	 * @since      1.0.0
	 */
	protected function handleNoExpandConfiguration()
	{
		$this->arguments['quioteExpandConfiguration'] = false;
	}
	
	
	/**
	 * Dispatch the test run.
	 * @param      array An array containing the command line arguments
	 * @param      bool  Whether exit() should be called with an appropriate shell
	 *                   exit status to indicate success or failures/errors.
	 * @return     int   The return process return code (if $exit was false)
	 * @since      1.0.0
	 */
	public static function dispatch($argv, $exit = true) {
		$command = new static();
		return $command->run($argv, $exit);
	}
	
	/**
	 * Show the help message.
	 * @since      1.0.0
	 */
    protected function showHelp()
	{
		parent::showHelp();
		echo <<<EOT

Quiote specific arguments:

  --environment <envname>   use environment named <envname> to run the tests.
                            Defaults to "testing".
  --include-suite <suites>  run only suites named <suite>, accepts a list of
                            suites, comma separated.
  --exclude-suite <suites>  run all but suites named <suite>, accepts a list
                            of suites, comma separated.
  --no-expand-configuration Don't expand configuration variables in the 
                            configuration file
 
NOTE:
  Unless --no-expand-configuration is given the configuration file given to
  PHPUnit is generated in Quiote's cache directory. So you can't use relative
  paths in the configuration file. Use  %quiote.app_dir%, %core.testing_dir% or
  something applicable to your case.


EOT;
	}

	/**
	 * Custom callback for test suite discovery.
	 * This is called by PHPUnit in the setup process, right after all command line 
	 * arguments have been parsed.
	 * @since      1.0.0
	 */
	protected function handleCustomTestSuite()
	{
		// ensure the bootstrap script doesn't run and bootstraps quiote another time
		define('QUIOTE_TESTING_BOOTSTRAPPED', true);
		Toolkit::clearCache();
		static::bootstrap($this->arguments['quioteEnvironment']);
		
		
		// use the default configuration only if another configuration was not given as command line argument
		$defaultConfigPath = Config::get('core.testing_dir') . '/config/phpunit.xml';
		if(empty($this->arguments['configuration']) && is_file($defaultConfigPath)) {
			$this->arguments['configuration'] = $defaultConfigPath;
		}
		
		$this->arguments['configuration'] = self::expandConfiguration($this->arguments['configuration']);

		if(count($this->options[1]) > 0) {
			// positional args were given, so the user specified a test or folder on the command line
			return;
		}
		
		$suites = require(ConfigCache::checkConfig(Config::get('core.testing_dir') . '/config/suites.xml'));
		
		$masterSuite = new TestSuite('Master');
		
		if($this->arguments['quioteIncludeSuites']) {
			foreach($this->arguments['quioteIncludeSuites'] as $name) {
				if(empty($suites[$name])) {
					throw new InvalidArgumentException(sprintf('Invalid suite name %1$s.', $name));
				}
				
				$masterSuite->addTest(self::createSuite($name, $suites[$name]));
			}
		} else {
			foreach($suites as $name => $suite) {
				if(!in_array($name, $this->arguments['quioteExcludeSuites'])) {
					$masterSuite->addTest(self::createSuite($name, $suite));
				}
			}
		}
		
		$this->arguments['test'] = $masterSuite;
	}
	
	/**
	 * Initialize a suite from the given instructions and add registered tests.
	 * @param      string Name of the suite
	 * @param      array  An array containing information about the suite
	 * @return     TestSuite The initialized test suite object.
	 * @since      1.0.0
	 */
	protected static function createSuite($name, array $suite)
	{
		$base = (null == $suite['base']) ? 'tests' : $suite['base'];
		if(!Toolkit::isPathAbsolute($base)) {
			$base = Config::get('core.testing_dir') . '/' . $base;
		}
		$s = new $suite['class']($name);
		if(!empty($suite['includes'])) {
			$files = iterator_to_array(new RecursiveIteratorIterator(
				new RecursiveDirectoryFilterIterator(
					new RecursiveDirectoryIterator($base),
					$suite['includes'],
					$suite['excludes']
				),
				RecursiveIteratorIterator::CHILD_FIRST
			));
			// ensure that the execution order of the tests is always in deterministic
			// order and doesn't depend on the filesystem order
			usort($files, fn($a, $b) => strcmp((string) $a->getPathName(), (string) $b->getPathName()));
			
			foreach($files as $finfo) {
				if($finfo->isFile()) {
					$s->addTestFile($finfo->getPathName());
				}
			}
		}
		foreach($suite['testfiles'] as $file) {
			if(!Toolkit::isPathAbsolute($file)) {
				$file = $base . '/' . $file;
			}
			$s->addTestFile($file);
		}
		return $s;
	}
	
	/**
	 * Runs Toolkit::expandDirectives() on all attributes and text nodes of
	 * the given file and writes a it to a new file in the Quiote cache directory.
	 * @param      string The path to the xml file
	 * @return     string The path to the expanded file
	 * @since      1.0.0
	 */
	private static function expandConfiguration($file) {
		// file does not exist, let PHPUnit handle that case
		if(!is_readable($file) || !is_file($file)) {
			return $file;
		}
		
		$doc = new DOMDocument();
		$doc->substituteEntities = true;
		$doc->load($file);
		$xpath = new DOMXPath($doc);
		$attributeNodes = $xpath->query('//@*');
		foreach($attributeNodes as $attributeNode) {
			$attributeNode->value = Toolkit::expandDirectives($attributeNode->value);
		}
		$textNodes = $xpath->query('//text()');
		foreach($textNodes as $textNode) {
			$textNode->nodeValue = Toolkit::expandDirectives($textNode->nodeValue);
		}
		
		$translatedFile = ConfigCache::getCacheName($file);
		ConfigCache::writeCacheFile($file, $translatedFile, $doc->saveXML());
		return $translatedFile;
	}
	
	/**
	 * Startup the Quiote core
	 * @param      string environment the environment to use for this session.
	 * @since      1.0.0
	 */
	public static function bootstrap($environment = null)
	{
		if($environment === null) {
			// no env given? let's read one from testing.environment
			$environment = Config::get('testing.environment');
		} elseif(Config::has('testing.environment') && Config::isReadonly('testing.environment')) {
			// env given, but testing.environment is read-only? then we must use that instead and ignore the given setting
			$environment = Config::get('testing.environment');
		}
		
		if($environment === null) {
			// still no env? oh man...
			throw new \Exception('You must supply an environment name to Testing::bootstrap() or set the name of the default environment to be used for testing in the configuration directive "testing.environment".');
		}
		
		// finally set the env to what we're really using now.
		Config::set('testing.environment', $environment, true, true);
		
		// bootstrap the framework for autoload, config handlers etc.
		Quiote::bootstrap($environment);
		
		ini_set('include_path', get_include_path().PATH_SEPARATOR.dirname(__DIR__));
		
		$GLOBALS['QUIOTE_CONFIG'] = Config::toArray();
	}
}

?>