<?php
namespace Quiote\Routing;

use Quiote\Context;
use Quiote\Exception\QuioteException;
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
		$routing = $this->getContext()->getRouting();
		
		if($this->hasParameter('route')) {
			// generate a route
			$route = $this->getParameter('route');
			
			$arguments = (array)$this->getParameter('arguments');
			// expand ${foo} in arguments using incoming parameters, this enables basic rewriting of arguments
			array_walk_recursive($arguments, function(&$argument) use($parameters): void { $argument = Toolkit::expandVariables($argument, $parameters); });
			
			$options = $this->getParameter('options', []);
			// prepare options; make sure URLs are absolute and separator is "&" by default
			if(is_array($options)) {
				// it's an array of options, not a gen options preset name; set our defaults
				if(!isset($options['separator'])) {
					$options['separator'] = '&';
				}
				if(!isset($options['relative'])) {
					$options['relative'] = false;
				}
			}
			
			$url = $routing->gen($route, $arguments, $options);
		} elseif($this->hasParameter('url')) {
			// just a plain URL to redirect to, but we still expand arguments
			$url = Toolkit::expandVariables(
				$this->getParameter('url'),
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
			$parts = [];
			foreach(['scheme', 'host', 'port', 'user', 'pass', 'path', 'query', 'fragment'] as $part) {
				if(($value = $this->getParameter($part)) !== null) {
					$parts[$part] = $value;
				}
			}
			
			if($parts) {
				$req = $this->getContext()->getRequest();
				/** @var mixed $req */
				// getUrl() exists on legacy WebRequest; guard via method_exists.
				$base = (method_exists($req,'getUrl')) ? (string)$req->getUrl() : ((($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'));
				$url = Toolkit::buildUrl(array_merge(parse_url($base), $parts));
			} else {
				// improper configuration for whatever reason; bail out
				return false;
			}
		}
		
		// create response and set redirect
		$response = $this->getContext()->createInstanceFor('response');
		if(!($response instanceof WebResponse)) {
			throw new QuioteException('HttpRedirectRoutingCallback can only be used in combination with WebResponse.');
		}
		$response->setRedirect($url, $this->getParameter('code', 302));
		return $response;
	}
}

?>