<?php
declare(strict_types=1);

namespace Sandbox\Modules\Dup\Actions;

use Quiote\Action\Action;
use Quiote\Routing\Attribute\Route;

#[Route('/dup/a', name: 'dup.same', methods: ['GET'])]
class AAction extends Action
{
	public function executeRead(): string	
	{
		return 'Success';
	}
}
