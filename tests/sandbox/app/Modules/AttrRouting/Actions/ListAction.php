<?php
declare(strict_types=1);

namespace Sandbox\Modules\AttrRouting\Actions;

use Quiote\Action\Action;
use Quiote\Routing\Attribute\Route;

#[Route('/attr-routing', name: 'attr_routing.list', methods: ['GET'])]
class ListAction extends Action
{
	public function executeRead(): string
	{
		return 'Success';
	}
}
