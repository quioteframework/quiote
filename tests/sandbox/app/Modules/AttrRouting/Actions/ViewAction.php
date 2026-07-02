<?php
declare(strict_types=1);

namespace Sandbox\Modules\AttrRouting\Actions;

use Quiote\Action\Action;
use Quiote\Routing\Attribute\Route;

#[Route('/attr-routing/{id}', name: 'attr_routing.view', methods: ['GET'], requirements: ['id' => '\d+'], outputType: 'html')]
class ViewAction extends Action
{
	public function executeRead()
	{
		return 'Success';
	}
}
