<?php
declare(strict_types=1);

namespace Sandbox\Modules\Dup\Actions;

use Quiote\Action\Action;
use Quiote\Routing\Attribute\Route;

#[Route('/dup/b', name: 'dup.same', methods: ['GET'])]
class BAction extends Action
{
	public function executeRead(): string
	{
		return 'Success';
	}
}
