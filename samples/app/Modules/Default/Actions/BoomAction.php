<?php
namespace SampleApp\Modules\Default\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

/**
 * Deliberately throws -- hit GET /boom to see how the framework renders an
 * unhandled exception. With core.developer_exceptions off (the default),
 * this should never leak this message or a trace to the client.
 */
class BoomAction extends Action
{
	public function executeRead(WebRequest $rd)
	{
		throw new \RuntimeException('Boom! This is a deliberately triggered error.');
	}

	public function getDefaultViewName()
	{
		return 'Success';
	}
}
