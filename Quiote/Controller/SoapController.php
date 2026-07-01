<?php
namespace Quiote\Controller;

/**
 * SoapController handles SOAP requests.
 * @since      1.0.0
 * @version    1.0.0
 */
use Quiote\Config\ConfigCache;
use Quiote\Config\APCuConfigCache;
use Quiote\Request\RequestDataHolder;
class SoapController extends Controller
{
	/**
	 * @param      RequestDataHolder Additional request data for later use.
	 */
	protected $dispatchArguments = null;
	
	/**
	 * @param      ExecutionContainer Specific execution container to run.
	 */
	protected $dispatchContainer = null;
	
	/**
	 * @param      SoapClient The soap client instance we use to access WSDL info.
	 */
	protected $soapClient = null;
	
	/**
	 * @param      SoapServer The soap server instance that handles the request.
	 */
	protected $soapServer = null;
	
	/**
	 * Get the soap client instance we use to access WSDL info.
	 * @return     SoapClient The soap client instance.
	 * @since      1.0.0
	 */
	public function getSoapClient()
	{
		return $this->soapClient;
	}
	
	/**
	 * Get the soap server instance we use to access WSDL info.
	 * @return     SoapServer The soap client instance.
	 * @since      1.0.0
	 */
	public function getSoapServer()
	{
		return $this->soapServer;
	}
	
	/**
	 * Do any necessary startup work after initialization.
	 * This method is not called directly after initialize().
	 * @since      1.0.0
	 */
	#[\Override]
    public function startup()
	{
		parent::startup();
		
		// user-supplied "wsdl" and "options" parameters
		$wsdl = $this->getParameter('wsdl');
		if(!$wsdl) {
			// no wsdl was specified, that means we generate one from the annotations in routing.xml
			$wsdl = $this->context->getRouting()->getWsdlPath();
		}
		$this->setParameter('wsdl', $wsdl);
		
		// get the name of the class to use for the client, defaults to PHP's own "SoapClient"
		$soapClientClass = $this->getParameter('soap_client_class', 'SoapClient');
		$soapClientOptions = $this->getParameter('soap_client_options', []);
		// get the name of the class to use for the server, defaults to PHP's own "SoapServer"
		$soapServerClass = $this->getParameter('soap_server_class', 'SoapServer');
		$soapServerOptions = $this->getParameter('soap_server_options', []);
		// get the name of the class to use for handling soap calls, defaults to Quiote's "SoapControllerCallHandler"
		$soapHandlerClass = $this->getParameter('soap_handler_class', 'SoapControllerCallHandler');
		
		// force client's soap version to be the same as the server's
		if(isset($soapServerOptions['soap_version'])) {
			$soapClientOptions['soap_version'] = $soapServerOptions['soap_version'];
		}
		
		// force client's cache_wsdl setting to be the same as the server's
		if(isset($soapServerOptions['cache_wsdl'])) {
			// and cast it to an int
			$soapServerOptions['cache_wsdl'] = (int)$soapServerOptions['cache_wsdl'];
			$soapClientOptions['cache_wsdl'] = $soapServerOptions['cache_wsdl'];
		}
		
		if(isset($soapServerOptions['features'])) {
			// cast this to an int
			$soapServerOptions['features'] = (int)$soapServerOptions['features'];
		}
		
		// create a client, so we can grab the functions and types defined in the wsdl (not possible from the server, duh)
		$this->soapClient = new $soapClientClass($wsdl, $soapClientOptions);
		
		if($this->getParameter('auto_classmap')) {
			// we have to create a classmap automatically.
			// to do that, we read the defined types, and set identical values for type and class name.
			$classmap = [];
			
			// with an optional prefix, of course.
			$prefix = $this->getParameter('auto_classmap_prefix', '');
			
			foreach($this->soapClient->__getTypes() as $definition) {
				if(preg_match('/^struct (\S+) \{$/m', $definition, $matches)) {
					$classmap[$matches[1]] = $prefix . $matches[1];
				}
			}
			
			if(isset($soapServerOptions['classmap'])) {
				$classmap = array_merge((array) $classmap, $soapServerOptions['classmap']);
			}
			
			$soapServerOptions['classmap'] = $classmap;
		}
		
		// create a server
		$this->soapServer = new $soapServerClass($wsdl, $soapServerOptions);
		
		$newSoapHandlerClass = $soapHandlerClass . 'WithAutoHeaders';
		
		// build the special extension class to the handler that contains methods for each of the headers
		if($this->getParameter('auto_headers', true)) {
			// the cache filename we'll be using
			if(defined('\QUIOTE_USE_APCU_CONFIG_CACHE') && \QUIOTE_USE_APCU_CONFIG_CACHE) {
				$cache = APCuConfigCache::getCacheName($soapHandlerClass, $this->context->getName());
			} else {
				$cache = ConfigCache::getCacheName($soapHandlerClass, $this->context->getName());
			}
			
			$isModified = defined('\QUIOTE_USE_APCU_CONFIG_CACHE') && \QUIOTE_USE_APCU_CONFIG_CACHE 
				? APCuConfigCache::isModified($wsdl, $cache)
				: ConfigCache::isModified($wsdl, $cache);
			
			if($isModified) {
				$doc = new \DOMDocument();
				$doc->load($wsdl);
				$xpath = new \DOMXPath($doc);
				$xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/wsdl/soap/');
				
				$code = [];
				
				$code[] = '<?php';
				$code[] = sprintf('class %s extends %s {', $newSoapHandlerClass, $soapHandlerClass);
				$code[] = '  protected $rd;';
				$code[] = '  public function __construct(Context $context) {';
				$code[] = '    parent::__construct($context);';
				$code[] = '    $this->rd = $this->context->getRequest()->getRequestData();';
				$code[] = '  }';
				
				$headers = [];
				
				/** @var \DOMElement $header */
				foreach($xpath->query('//soap:header') as $header) {
					$name = $header->getAttribute('part');
					
					if(in_array($name, $headers)) {
						continue;
					}
					$headers[] = $name;
					
					$code[] = sprintf('  public function %s($value) {', $name);
					$code[] = sprintf('    $this->rd->setHeader(%s, $value);', var_export($name, true));
					$code[] = '  }';
				}
				
				$code[] = '}';
				$code[] = '?>';
				
				$code = implode("\n", $code);
				
				if(defined('\QUIOTE_USE_APCU_CONFIG_CACHE') && \QUIOTE_USE_APCU_CONFIG_CACHE) {
					APCuConfigCache::writeCacheFile($soapHandlerClass, $cache, $code);
				} else {
					ConfigCache::writeCacheFile($soapHandlerClass, $cache, $code);
				}
			}
			
			include($cache);
		}
		
		// give it a class that handles method calls
		// that class uses __call
		// the class ctor gets the context as the first argument
		$this->soapServer->setClass($newSoapHandlerClass, $this->context);
	}
	/**
	 * Dispatch a request
	 * @param      RequestDataHolder  An optional request data holder object
	 *                                     with additional request data.
	 * @param      ExecutionContainer An optional execution container that,
	 *                                     if given, will be executed right away,
	 *                                     skipping routing execution.
	 * @return     Response The response produced during this dispatch call.
	 * @since      1.0.0
	 */
	#[\Override]
    public function dispatch(?RequestDataHolder $arguments = null, ?ExecutionContainer $container = null)
	{
		// Remember The Milk... err... the arguments given.
		$this->dispatchArguments = $arguments;
		// and the container, too, if there was one
		$this->dispatchContainer = $container;
		
		// handle the request. the aforementioned __call will be run next
		// we use the input from the request as the argument, it contains the SOAP request
		// no need to send the response as SoapServer does that
		$this->soapServer->handle($this->context->getRequest()->getInput());
	}
	
	/**
	 * A method that is called in the __call overload by the SOAP call handler.
	 * All it does is call parent::dispatch() to prevent an infinite loop.
	 * @since      1.0.0
	 */
	public function doDispatch()
	{
		try {
			// return the content so SoapServer can send it.
			// SoapResponse::send() does not send the content, but sets the headers on the SoapServer
			return parent::dispatch($this->dispatchArguments, $this->dispatchContainer);
		} catch(\SoapFault $f) {
			$this->response->clear();
			$this->response->setContent($f);
			// return the content so SoapServer can send it.
			return $this->response;
		}
	}
}

?>