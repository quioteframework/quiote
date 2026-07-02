<?php
declare(strict_types=1);

namespace Sandbox\Modules\AttrRouting\Actions\Index;

use Quiote\Action\Action;
use Quiote\Routing\Attribute\Route;

#[Route('/attr-routing/new', name: 'attr_routing.add', methods: ['POST'])]
class AddAction extends Action
{
	public function executeWrite()
	{
		return 'Success';
	}
}
