<?php
namespace Quiote\Routing;

use Quiote\Context;
use Quiote\Exception\ConfigurationException;
use Quiote\Response\WebResponse;
use Quiote\Util\Toolkit;

/**
 * HttpRedirectRoutingCallback allows redirection of a matched route to a
 * route or URL. Matched arguments can be rewritten.
 * You need to configure this callback using parameters in the <callback> block.
 * To redirect to a URL, use the "url" configuration parameter and supply the
 * destination URL as the value.
 * To redirect to a route, use the "route" configuration parameter and supply
 * the name of the route to generate.
 * You may pass an arbitrary array of arguments in parameter "arguments". If a
 * parameter value contains a valid PHP variable literal such as $foo, ${foo} or
 * {$foo}, the literal will be replaced with the value of the argument "foo" in
 * the matched route the callback is defined on.
 * Default routing gen() options for generating are "relative" set to false and
 * "separator" set to "&". You may pass an array of options or the name of a
 * routing gen() options preset in configuration in parameter "options".
 * By default, the HTTP status code 302 is used for redirects. You can define a
 * different status code through configuration parameter "code".
 * @since      1.0.0
 * @version    1.0.0
 */
class HttpRedirectRoutingCallback extends RoutingCallback
{
	/**
	 * Initialize the callback instance.
	 * @param      Context $context An Context instance.
	 * @param      array<mixed, mixed> $route   An array with information about the route.
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(Context $context, array &$route)
	{
		parent::initialize($context, $route);
	}

	/**
	 * Container-less match hook.
	 * @param array<string, mixed> $parameters Matched parameters (modifiable for rewrite).
	 * @param mixed $legacyContainer Unused; retained for signature compatibility.
	 * @return bool|WebResponse false to reject the match on misconfiguration, otherwise
	 *                          a WebResponse carrying the redirect to be sent to the client.
	 * @since      1.0.0
	 */
	#[\Override]
	public function onMatched(array &$parameters, $legacyContainer = null)
	{
		$routing = $this->getContext()->getContainer()->get(\Quiote\Routing\Routing::class);
		
		if($this->hasParameter('route')) {
			// generate a route
			$route = $this->getParameter('route');
			if($route !== null && !is_string($route)) {
				throw new ConfigurationException('HttpRedirectRoutingCallback parameter "route" must be a string.');
			}

			$rawArguments = $this->getParameter('arguments');
			if($rawArguments !== null && !is_array($rawArguments)) {
				throw new ConfigurationException('HttpRedirectRoutingCallback parameter "arguments" must be an array.');
			}
			/** @var array<string, mixed> $arguments */
			$arguments = (array)$rawArguments;
			// expand ${foo} in arguments using incoming parameters, this enables basic rewriting of arguments
			array_walk_recursive($arguments, function(&$argument) use($parameters): void { $argument = Toolkit::expandVariables(is_scalar($argument) ? (string)$argument : null, $parameters); });

			$options = $this->getParameter('options', []);
			if(!is_array($options)) {
				throw new ConfigurationException('HttpRedirectRoutingCallback parameter "options" must be an array.');
			}
			/** @var array<string, mixed> $options */
			// prepare options; make sure URLs are absolute and separator is "&" by default
			// it's an array of options, not a gen options preset name; set our defaults
			if(!isset($options['separator'])) {
				$options['separator'] = '&';
			}
			if(!isset($options['relative'])) {
				$options['relative'] = false;
			}

			$url = $routing->gen($route, $arguments, $options);
		} elseif($this->hasParameter('url')) {
			$urlTemplate = $this->getParameter('url');
			if(!is_string($urlTemplate)) {
				throw new ConfigurationException('HttpRedirectRoutingCallback parameter "url" must be a string.');
			}
			// just a plain URL to redirect to, but we still expand arguments
			$url = Toolkit::expandVariables(
				$urlTemplate,
				array_map(
					function($value) {
						if(is_scalar($value)) {
							// Mirrors the URL-encoding Routing::gen() applies to generated path
							// segments; there is no dedicated escaping method on Routing itself.
							return rawurlencode((string)$value);
						} else {
							return '';
						}
					},
					$parameters
				)
			);
		} else {
			$scheme = $this->stringPartParameter('scheme');
			$host = $this->stringPartParameter('host');
			$port = $this->portPartParameter();
			$user = $this->stringPartParameter('user');
			$pass = $this->stringPartParameter('pass');
			$path = $this->stringPartParameter('path');
			$query = $this->stringPartParameter('query');
			$fragment = $this->stringPartParameter('fragment');

			if($scheme === null && $host === null && $port === null && $user === null && $pass === null && $path === null && $query === null && $fragment === null) {
				// improper configuration for whatever reason; bail out
				return false;
			}

			$base = $this->getContext()->getContainer()->get(\Quiote\Request\WebRequest::class)->getUrl();
			$baseParts = parse_url($base);
			$parts = $baseParts !== false ? $baseParts : [];
			if($scheme !== null) { $parts['scheme'] = $scheme; }
			if($host !== null) { $parts['host'] = $host; }
			if($port !== null) { $parts['port'] = $port; }
			if($user !== null) { $parts['user'] = $user; }
			if($pass !== null) { $parts['pass'] = $pass; }
			if($path !== null) { $parts['path'] = $path; }
			if($query !== null) { $parts['query'] = $query; }
			if($fragment !== null) { $parts['fragment'] = $fragment; }
			$url = Toolkit::buildUrl($parts);
		}
		
		// create response and set redirect
		$response = $this->getContext()->getContainer()->get(\Quiote\Response\WebResponse::class);
		$code = $this->getParameter('code', 302);
		if(!is_int($code) && !is_string($code)) {
			throw new ConfigurationException('HttpRedirectRoutingCallback parameter "code" must be an int or string.');
		}
		$response->setRedirect($url, $code);
		return $response;
	}

	/**
	 * Reads a URL-part configuration parameter, requiring it to be a string
	 * when present.
	 * @throws     ConfigurationException If the parameter is set but not a string.
	 * @since      1.0.0
	 */
	private function stringPartParameter(string $name): ?string
	{
		$value = $this->getParameter($name);
		if($value === null) {
			return null;
		}
		if(!is_string($value)) {
			throw new ConfigurationException(sprintf('HttpRedirectRoutingCallback parameter "%s" must be a string.', $name));
		}
		return $value;
	}

	/**
	 * Reads the "port" configuration parameter, requiring it to be an int or
	 * string when present.
	 * @throws     ConfigurationException If the parameter is set but not an int or string.
	 * @since      1.0.0
	 */
	private function portPartParameter(): int|string|null
	{
		$value = $this->getParameter('port');
		if($value === null) {
			return null;
		}
		if(!is_int($value) && !is_string($value)) {
			throw new ConfigurationException('HttpRedirectRoutingCallback parameter "port" must be an int or string.');
		}
		return $value;
	}
}

?>