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
namespace Agavi\Routing;

use Agavi\AgaviContext;
use Agavi\Util\AgaviToolkit;

/**
 * AgaviWebRouting sets the prefix and input with some magic from the request
 * uri and path_info
 *
 * @package    agavi
 * @subpackage routing
 *
 * @author     Dominik del Bondio <ddb@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
class AgaviWebRouting extends AgaviRouting
{
	/**
	 * @var        string The path to the application's root with trailing slash.
	 */
	protected $basePath = '';

	/**
	 * @var        string The URL to the application's root with trailing slash.
	 */
	protected $baseHref = '';

	/**
	 * @var        array The GET parameters that were passed in the URL.
	 */
	protected $inputParameters = [];

	/**
	 * @var        array arg_separator.input as defined in php.ini, exploded
	 */
	protected $argSeparatorInput = ['&'];

	/**
	 * @var        string arg_separator.output as defined in php.ini
	 */
	protected $argSeparatorOutput = '&amp;';

	/**
	 * @var        bool Whether modern URL rewriting was detected
	 */
	protected $modernRewriteDetected = false;

	/**
	 * Constructor.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function __construct()
	{
		parent::__construct();

		$this->defaultGenOptions = array_merge($this->defaultGenOptions, [
			// separator, typically &amp; for HTML, & otherwise
			'separator' => '&amp;',
			// whether or not to append the SID if necessary
			'use_trans_sid' => false,
			// scheme, or true to include, or false to block
			'scheme' => null,
			// authority, or true to include, or false to block
			'authority' => null,
			// host, or true to include, or false to block
			'host' => null,
			// port, or true to include, or false to block
			'port' => null,
			// fragment identifier (#foo)
			'fragment' => null,
		]);
		
		$this->argSeparatorInput = str_split(ini_get('arg_separator.input'));
		$this->argSeparatorOutput = ini_get('arg_separator.output');
	}

	/**
	 * Initialize the routing instance.
	 *
	 * @param      AgaviContext The Context.
	 * @param      array        An array of initialization parameters.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @author     Veikko Mäkinen <veikko@veikkomakinen.com>
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	#[\Override]
    public function initialize(AgaviContext $context, array $parameters = [])
	{
		parent::initialize($context, $parameters);

		$rq = $this->context->getRequest();
		
		// Handle case where request is not set (e.g., in tests)
		if($rq === null) {
			return;
		}

		$rd = $rq->getRequestData();

		// 'scheme://authority' is necessary so parse_url doesn't stumble over '://' in the request URI
		$ru = array_merge(['path' => '', 'query' => ''], parse_url('scheme://authority' . $rq->getRequestUri()));

		if(isset($_SERVER['QUERY_STRING'])) {
			$qs = $_SERVER['QUERY_STRING'];
		} else {
			$qs = '';
		}

		// Enhanced rewrite detection for modern web servers (Apache, Nginx, Caddy, FrankenPHP)
		// Original logic: when rewriting, apache strips one (not all) trailing ampersand from the end of QUERY_STRING... normalize:
		$apacheRewriteDetected = (preg_replace('/&+$/D', '', (string) $qs) !== preg_replace('/&+$/D', '', (string) $ru['query']));
		
		// Additional detection for FrankenPHP and modern servers:
		// We check if the script name (e.g., 'index.php') is missing from the request URI path,
		// which indicates that URL rewriting is stripping it out (clean URLs)
		$this->modernRewriteDetected = false;
		if(isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '') {
			$scriptName = $_SERVER['SCRIPT_NAME'];
			$requestUri = $_SERVER['REQUEST_URI'] ?? '';
			
			// Remove query string from request URI for comparison
			$requestPath = $requestUri;
			if(($pos = strpos($requestPath, '?')) !== false) {
				$requestPath = substr($requestPath, 0, $pos);
			}
			
			// More precise rewrite detection: 
			// 1. If request path contains the script name, no rewriting
			// 2. If request path is just the directory of script name, no rewriting (default document)
			// 3. Only detect rewriting if we have a path that goes beyond the script directory
			//    but doesn't contain the script name
			$scriptDir = dirname($scriptName);
			if($scriptDir === '.') {
				$scriptDir = '/';
			}
			
			// Normalize paths
			if(!str_ends_with($scriptDir, '/')) {
				$scriptDir .= '/';
			}
			if(!str_starts_with($requestPath, '/')) {
				$requestPath = '/' . $requestPath;
			}
			if(!str_ends_with($requestPath, '/')) {
				$requestPath .= '/';
			}
			
			// If request contains the script name explicitly, no rewriting
			if(str_contains($requestUri, basename($scriptName))) {
				$this->modernRewriteDetected = false;
			}
			// If request path is just the script directory (or root), this is default document serving, not rewriting
			elseif($requestPath === $scriptDir) {
				$this->modernRewriteDetected = false;
			}
			// If we have a path that goes beyond the script directory but doesn't contain the script name, it's rewriting
			elseif(str_starts_with($requestPath, $scriptDir) && strlen($requestPath) > strlen($scriptDir)) {
				$this->modernRewriteDetected = true;
			}
			else {
				$this->modernRewriteDetected = false;
			}
		}
		
		$rewritten = $apacheRewriteDetected || $this->modernRewriteDetected;

		if($this->isEnabled() && $rewritten) {
			// strip the one trailing ampersand, see above
			$queryWasEmptied = false;
			$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
			
			if($ru['query'] !== '' && str_contains($serverSoftware, 'Apache')) {
				$ru['query'] = preg_replace('/&$/D', '', (string) $ru['query']);
				if($ru['query'] == '') {
					$queryWasEmptied = true;
				}
			}

			$stripFromQuery = '&' . $ru['query'];
			if($ru['query'] == '' && !$queryWasEmptied && str_contains($serverSoftware, 'Apache')) {
				// if the query is empty, simply give apache2 nothing instead of an "&", since that could kill a real trailing ampersand in the path, as Apache strips those from the query string (which has the rewritten path), but not the request uri
				$stripFromQuery = '';
			}
			$this->input = preg_replace('/' . preg_quote($stripFromQuery, '/') . '$/D', '', (string) $qs);

			// Apache 2 specific handling
			if(str_contains($serverSoftware, 'Apache/2')) {
				$sru = $_SERVER['REQUEST_URI'];
				
				if(($fqmp = strpos((string) $sru, '?')) !== false && ($fqmp == strlen((string) $sru)-1)) {
					// strip a trailing question mark, but only if it really is the query string separator (i.e. the only question mark in the URI)
					$sru = substr((string) $sru, 0, -1);
				} elseif($ru['query'] !== '' || $queryWasEmptied) {
					// if there is a trailing ampersand (in query string or path, whatever ends the URL), strip it (but just one)
					$sru = preg_replace('/&$/D', '', (string) $sru);
				}
				
				// multiple consecutive slashes got lost in our input thanks to an apache bug
				// let's fix that
				$cqs = preg_replace('#/{2,}#', '/', rawurldecode((string) $ru['query']));
				$cru = preg_replace('#/{2,}#', '/', rawurldecode((string) $sru));
				$tmp = preg_replace('/' . preg_quote($this->input . (($cqs != '' || $queryWasEmptied) ? '?' . $cqs : ''), '/') . '$/D', '', (string) $cru);
				$input = preg_replace('/^' . preg_quote((string) $tmp, '/') . '/', '', (string) $sru);
				if($ru['query'] !== '' || $queryWasEmptied) {
					$input = preg_replace('/' . preg_quote('?' . $ru['query'], '/') . '$/D', '', $input);
				}
				$this->input = $input;
			} elseif($this->modernRewriteDetected) {
				// For FrankenPHP and modern servers with clean URL rewriting
				// Extract the input path by removing the base path from REQUEST_URI
				$requestUri = $_SERVER['REQUEST_URI'] ?? '';
				$requestPath = $requestUri;
				
				// Remove query string
				if(($pos = strpos($requestPath, '?')) !== false) {
					$requestPath = substr($requestPath, 0, $pos);
				}
				
				// Get the directory of the script
				$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
				if($scriptDir === '.' || $scriptDir === '/') {
					$scriptDir = '';
				}
				
				// Remove the script directory to get the input path
				if($scriptDir !== '' && str_starts_with($requestPath, $scriptDir)) {
					$this->input = substr($requestPath, strlen($scriptDir));
				} else {
					$this->input = $requestPath;
				}
				
				// Ensure input starts with /
				if(!str_starts_with($this->input, '/')) {
					$this->input = '/' . $this->input;
				}
			}

			// URL decoding - handle decoding properly for different server configurations
			$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
			$shouldDecode = true;
			
			// Special cases where we should NOT decode:
			// 1. IIS with URL Rewrite Module (already decoded)
			// 2. Some Apache 1.x configurations where PATH_INFO is already decoded
			if(str_contains($serverSoftware, 'Microsoft-IIS') && isset($_SERVER['UNENCODED_URL'])) {
				$shouldDecode = false;
			}
			// For Apache 1.x, only skip decoding if we're NOT in a rewrite scenario
			// because in rewrite scenarios, the input comes from QUERY_STRING which needs decoding
			elseif(str_contains($serverSoftware, 'Apache/1') && !$apacheRewriteDetected) {
				$shouldDecode = false;
			}
			
			if($shouldDecode) {
				$this->input = rawurldecode($this->input);
			}

			// Calculate prefix by removing the input from the request path
			$decodedPath = rawurldecode((string) $ru['path']);
			if($this->modernRewriteDetected) {
				// For modern rewrite engines, the prefix should be the base directory without the input path
				$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
				$scriptDir = dirname($scriptName);
				if($scriptDir === '.' || $scriptDir === '/') {
					$this->basePath = $this->prefix = '';
				} else {
					$this->basePath = $this->prefix = $scriptDir;
				}
			} else {
				// Traditional Apache rewrite logic
				$this->basePath = $this->prefix = preg_replace('/' . preg_quote($this->input, '/') . '$/D', '', $decodedPath);
			}

			// that was easy. now clean up $_GET and the Request
			$parsedRuQuery = $parsedInput = '';
			parse_str((string) $ru['query'], $parsedRuQuery);
			parse_str($this->input, $parsedInput);
			
			foreach(array_diff(array_keys($parsedInput), array_keys($parsedRuQuery)) as $unset) {
				// our element is in $_GET
				unset($_GET[$unset]);
				unset($GLOBALS['HTTP_GET_VARS'][$unset]);
				// if it is not also in $_POST, then we need to remove it from the request params
				if(!isset($_POST[$unset])) {
					$rd->removeParameter($unset);
					// and from $_REQUEST, too!
					unset($_REQUEST[$unset]);
				}
			}
		} else {
			// Fallback logic when rewriting is not detected
			$sn = $_SERVER['SCRIPT_NAME'] ?? '';
			$path = rawurldecode((string) $ru['path']);

			$appendFrom = 0;
			$this->prefix = AgaviToolkit::stringBase($sn, $path, $appendFrom);
			$this->prefix .= substr((string) $sn, $appendFrom);

			$this->input = substr($path, $appendFrom);
			
			$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
			// Enhanced server detection for URL decoding
			if(!str_contains($serverSoftware, 'Microsoft-IIS') || 
			   isset($_SERVER['HTTP_X_REWRITE_URL']) || 
			   !isset($_SERVER['GATEWAY_INTERFACE']) || 
			   !str_contains($_SERVER['GATEWAY_INTERFACE'], 'CGI')) {
				// don't do that for IIS-CGI, it's already rawurldecode()d there
				$this->input = rawurldecode($this->input);
			}

			$this->basePath = str_replace('\\', '/', dirname($this->prefix));
		}

		$this->inputParameters = $_GET;

		if(!$this->input) {
			$this->input = "/";
		}

		if(!str_ends_with((string) $this->basePath, '/')) {
			$this->basePath .= '/';
		}

		$this->baseHref = $rq->getUrlScheme() . '://' . $rq->getUrlAuthority() . $this->basePath;
	}

	/**
	 * Retrieve the base path where the application's root sits
	 *
	 * @return     string A path string, including a trailing slash.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getBasePath()
	{
		return $this->basePath;
	}

	/**
	 * Retrieve the full URL to the application's root.
	 *
	 * @return     string A URL string, including the protocol, the server port
	  *                   (if necessary) and the path including a trailing slash.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getBaseHref()
	{
		return $this->baseHref;
	}
	
	/**
	 * Generate a formatted Agavi URL.
	 *
	 * @param      string A route name.
	 * @param      array  An associative array of parameters.
	 * @param      mixed  An array of options, or the name of an options preset.
	 *
	 * @return     string The generated URL.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	#[\Override]
    public function gen($route, array $params = [], $options = [])
	{
		$req = $this->context->getRequest();

		if(str_ends_with((string) $route, '*')) {
			$options['refill_all_parameters'] = true;
			$route = substr((string) $route, 0, -1);
		}

		$options = $this->resolveGenOptions($options);

		$aso = $this->argSeparatorOutput;
		if($options['separator'] != $aso) {
			$aso = $options['separator'];
		}

		if($options['use_trans_sid'] === true && defined('SID') && SID !== '') {
			$params = array_merge($params, [session_name() => session_id()]);
		}

		if($route === null && empty($params)) {
			$retval = $req->getRequestUri();
			$retval = str_replace(['[', ']', '\''], ['%5B', '%5D', '%27'], $retval);
			// much quicker than str_replace($this->argSeparatorInput, array_fill(0, count($this->argSeparatorInput), $aso), $retval)
			foreach($this->argSeparatorInput as $char) {
				$retval = str_replace($char, $aso, $retval);
			}
		} else {
			if($this->isEnabled()) {
				// the route exists and routing is enabled, the parent method handles it

				$append = '';

				[$path, $usedParams, $options, $extraParams, $isNullRoute] = parent::gen($route, $params, $options);
				
				if($isNullRoute) {
					// add the incoming parameters from the request uri for gen(null) and friends
					$extraParams = array_merge($this->inputParameters, $extraParams);
				}
				if(count($extraParams) > 0) {
					$append = http_build_query($extraParams, '', $aso);
					if($append !== '') {
					  $append = '?' . $append;
					}
				}
			} else {
				// the route exists, but we must create a normal index.php?foo=bar URL.

				$isNullRoute = false;
				$routes = $this->getAffectedRoutes($route, $isNullRoute);
				if($isNullRoute) {
					$params = array_merge($this->inputParameters, $params);
				}
				if(count($routes) == 0) {
					$path = $route;
				}

				// we collect the default parameters from the route and make sure
				// new parameters don't overwrite already defined parameters
				$defaults = [];

				$ma = $req->getParameter('module_accessor');
				$aa = $req->getParameter('action_accessor');

				foreach($routes as $route) {
					if(isset($this->routes[$route])) {
						$r = $this->routes[$route];
						$myDefaults = [];

						foreach($r['opt']['defaults'] as $key => $default) {
							$myDefaults[$key] = $default->getValue();
						}
						if($r['opt']['module']) {
							$myDefaults[$ma] = $r['opt']['module'];
						}
						if($r['opt']['action']) {
							$myDefaults[$aa] = $r['opt']['action'];
						}

						$defaults = array_merge($myDefaults, $defaults);
					}
				}

				$params = array_merge($defaults, $params);
			}
			
			if(!isset($path)) {
				// the route does not exist. we generate a normal index.php?foo=bar URL.
				// However, for modern servers with URL rewriting, we might want to avoid index.php
				$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
				
				// If we detected modern rewriting earlier, try to use a clean path
				if($this->modernRewriteDetected && $scriptName !== '') {
					// Use the directory part of the script name without the script file itself
					$scriptDir = dirname($scriptName);
					$path = ($scriptDir === '/' || $scriptDir === '.') ? '/' : $scriptDir . '/';
				} else {
					// Traditional fallback with script name
					$path = $scriptName;
				}
			}
			
			if(!isset($path)) {
				// routing was off; the name of the route is the input
			}
			if(!isset($append)) {
				$append = '?' . http_build_query($params, '', $aso);
			}

			$retval = $path . $append;
		}

		if(
			!$options['relative'] ||
			($options['relative'] && (
				$options['scheme'] !== null ||
				$options['authority'] !== null ||
				$options['host'] !== null ||
				$options['port'] !== null
			))
		) {
			$scheme = false;
			if($options['scheme'] !== false) {
				$scheme = ($options['scheme'] ?? $req->getUrlScheme());
			}

			$authority = '';

			if($options['authority'] === null) {
				if($options['host'] !== null && $options['host'] !== false) {
					$authority = $options['host'];
				} elseif($options['host'] === false) {
					$authority = '';
				} else {
					$authority = $req->getUrlHost();
				}
				$port = null;
				if($options['port'] !== null && $options['port'] !== false) {
					if(AgaviToolkit::isPortNecessary($options['scheme'] !== null && $options['scheme'] !== false ? $options['scheme'] : $req->getUrlScheme(), $options['port'])) {
						$port = $options['port'];
					} else {
						$port = null;
					}
				} elseif($options['port'] === false) {
					$port = null;
				} elseif($options['scheme'] === null) {
					if(!AgaviToolkit::isPortNecessary($req->getUrlScheme(), $port = $req->getUrlPort())) {
						$port = null;
					}
				}
				if($port !== null) {
					$authority .= ':' . $port;
				}
			} elseif($options['authority'] !== false) {
				$authority = $options['authority'];
			}

			if($scheme === false) {
				// nothing at all, e.g. when displaying a URL without the "http://" prefix
				$scheme = '';
			} elseif(trim((string) $scheme) === '') {
				// a protocol-relative URL (see #1224)
				$scheme = '//';
			} else {
				// given scheme plus "://"
				$scheme .= '://';
			}
			
			$retval = $scheme . $authority . $retval;
		}

		if($options['fragment'] !== null) {
			$retval .= '#' . $options['fragment'];
		}

		return $retval;
	}

	/**
	 * Escapes an argument to be used in an generated route.
	 *
	 * @param      string The argument to be escaped.
	 *
	 * @return     string The escaped argument.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	#[\Override]
    public function escapeOutputParameter($string)
	{
		if ($string === null) {
			return '';
		}
		return rawurlencode($string);
	}

}

?>