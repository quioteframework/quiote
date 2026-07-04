<?php
namespace Quiote\Exception\Rendering\Whoops;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Quiote\Exception\Rendering\ExceptionRenderer;
use Quiote\Exception\Rendering\NegotiatesContent;
use Throwable;
use Whoops\Handler\JsonResponseHandler;
use Whoops\Handler\PlainTextHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

/**
 * Full-detail developer renderer built on filp/whoops -- the "shiny page"
 * shiny.php always tried and failed to be. Only ever used when
 * core.developer_exceptions is explicitly enabled; never the default.
 *
 * Whoops\Run is configured with allowQuit(false) and writeToOutput(false)
 * so handleException() returns its generated markup as a string instead of
 * echoing and calling exit() -- required for worker-mode (FrankenPHP)
 * safety, since exit() would kill the persistent process.
 * @since      1.0.0
 */
final class WhoopsRenderer implements ExceptionRenderer
{
	use NegotiatesContent;

	public function render(Throwable $e, ServerRequestInterface $request, int $status, ?string $correlationId): ResponseInterface
	{
		if ($this->wantsJson($request)) {
			$handler = new JsonResponseHandler();
			$handler->addTraceToOutput(true);
			return $this->run($handler, $e, $status, 'application/json');
		}

		if ($this->wantsPlainText($request)) {
			$handler = new PlainTextHandler();
			return $this->run($handler, $e, $status, 'text/plain');
		}

		$handler = new PrettyPageHandler();
		// Whoops silently no-ops PrettyPageHandler under PHP_SAPI === 'cli' unless
		// told otherwise -- irrelevant to a real FrankenPHP worker (SAPI is not
		// 'cli' there) but this also makes rendering deterministic under PHPUnit.
		$handler->handleUnconditionally(true);
		if ($correlationId) {
			$handler->addDataTable('Quiote', ['Correlation-Id' => $correlationId]);
		}
		return $this->run($handler, $e, $status, 'text/html');
	}

	private function run(\Whoops\Handler\HandlerInterface $handler, Throwable $e, int $status, string $contentType): ResponseInterface
	{
		$run = new Run();
		$run->allowQuit(false);
		$run->writeToOutput(false);
		$run->sendHttpCode(false);
		$run->pushHandler($handler);

		$body = $run->handleException($e);

		return new Response($status, ['Content-Type' => $contentType . '; charset=utf-8'], $body);
	}
}
