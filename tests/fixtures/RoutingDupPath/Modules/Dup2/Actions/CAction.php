<?php
declare(strict_types=1);

namespace Sandbox\Modules\Dup2\Actions;

use Quiote\Action\Action;
use Quiote\Routing\Attribute\Route;

#[Route('/dup2/shared', name: 'dup2.c', methods: ['GET'])]
class CAction extends Action
{
	public function executeRead(): string
	{
		return 'Success';
	}
}
