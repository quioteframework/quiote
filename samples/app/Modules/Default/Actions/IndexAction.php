<?php
namespace SampleApp\Modules\Default\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

class IndexAction extends Action
{
	public function executeRead(WebRequest $rd)
	{
		return 'Success';
	}

	public function getDefaultViewName()
	{
		return 'Success';
	}
}
