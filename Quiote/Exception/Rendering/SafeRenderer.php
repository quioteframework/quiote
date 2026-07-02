<?php
namespace Quiote\Exception\Rendering;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Default renderer: never leaks exception internals. No message, no class
 * name, no trace, no `X-Quiote-Error-Type` header -- just a generic body
 * plus the correlation id, so an operator can find the real detail in the
 * logs without a client ever seeing it. Used whenever
 * core.developer_exceptions is off (the default).
 * @since      1.0.0
 */
final class SafeRenderer implements ExceptionRenderer
{
	use NegotiatesContent;

	public function render(Throwable $e, ServerRequestInterface $request, int $status, ?string $correlationId): ResponseInterface
	{
		if ($this->wantsJson($request)) {
			$payload = ['error' => $this->genericMessage($status), 'status' => $status];
			if ($correlationId) {
				$payload['correlation_id'] = $correlationId;
			}
			return new Response($status, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($payload, JSON_UNESCAPED_SLASHES));
		}

		if ($this->wantsPlainText($request)) {
			$body = 'Internal error';
			if ($correlationId) {
				$body .= "\nCorrelation-Id: " . $correlationId;
			}
			return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
		}

		$body = $this->renderHtml($status, $correlationId);
		return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
	}

	private function genericMessage(int $status): string
	{
		return $status >= 500 ? 'Internal Server Error' : 'Request Error';
	}

	private function renderHtml(int $status, ?string $correlationId): string
	{
		$message = htmlspecialchars($this->genericMessage($status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$cidLine = $correlationId
			? '<p>Correlation-Id: ' . htmlspecialchars($correlationId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
			: '';
		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{$message}</title></head>
<body>
<h1>{$message}</h1>
{$cidLine}
</body>
</html>
HTML;
	}
}
