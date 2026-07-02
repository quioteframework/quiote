<?php
namespace SampleApp\Modules\Default\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;
use Quiote\Routing\Attribute\Route;

/**
 * Routed via #[Route] instead of a line in AppRouting -- see
 * docs/ROUTING_AND_CLI_PLAN.md. AppRouting::build() pulls this in with
 * AttributeRoutes::mergeInto() alongside its hand-written routes.
 */
#[Route('/contact', name: 'contact', methods: ['GET'])]
class ContactAction extends Action
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
