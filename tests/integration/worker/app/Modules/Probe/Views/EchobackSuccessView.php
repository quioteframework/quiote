<?php

declare(strict_types=1);

namespace WorkerProbe\Modules\Probe\Views;

use Quiote\Exception\ViewException;
use Quiote\Request\WebRequest;
use Quiote\View\View;

/**
 * Reports what the app actually saw, so the integration tests can assert on the
 * request the runtime built rather than only on the response status: the URL the
 * app would generate (proxy correction), the superglobals it can read
 * (hydration), and the body it received.
 */
final class EchobackSuccessView extends View
{
	public function execute(WebRequest $rd): never
	{
		throw new ViewException(sprintf(
			'The view "%1$s" does not implement an "execute%2$s()" method for this output type.',
			static::class,
			ucfirst(strtolower($this->getCurrentOutputType()->getName()))
		));
	}

	public function executeHtml(WebRequest $rd): void
	{
		$this->loadLayout();
		$this->getResponse()->setContentType('application/json');
		$this->setAttribute('payload', [
			'method' => $rd->getMethod(),
			'scheme' => $rd->getUri()->getScheme(),
			'host' => $rd->getUri()->getHost(),
			'path' => $rd->getUri()->getPath(),
			'query' => $rd->getQueryParams(),
			'protocol' => $rd->getProtocolVersion(),
			'header_x_probe' => $rd->getHeaderLine('X-Probe'),
			'body' => (string) $rd->getBody(),
			'parsed_body' => $rd->getParsedBody(),
			// Hydrated by SuperglobalBridge off-SAPI; supplied by the SAPI otherwise.
			'server' => [
				'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
				'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? null,
				'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? null,
				'HTTP_X_PROBE' => $_SERVER['HTTP_X_PROBE'] ?? null,
				'REQUEST_TIME_FLOAT_IS_SET' => isset($_SERVER['REQUEST_TIME_FLOAT']),
			],
			'get' => $_GET,
			'post' => $_POST,
			'runtime' => \Quiote\Runtime\Worker\WorkerRuntimeInfo::alias(),
		]);
	}
}
