<?php
declare(strict_types=1);

namespace Sandbox\App\Routing;

use Quiote\Routing\AttributeRoutes;
use Quiote\Routing\Routing;
use Sandbox\App\Routing\Generated\Routes;

/**
 * Test-only fixture proving routes:list's Source column: file-based routes
 * (from the same generated Routes::build() SandboxRouting uses) merged with
 * #[Route]-attributed ones (AttrRouting fixture module), exactly like
 * samples/app/Routing/AppRouting.php does for its /contact route. Wired to
 * the "routes-list-cli-test" context in Config/factories.xml -- see
 * RoutesListCommandTest.
 */
final class AttributeMergedRouting extends Routing
{
	protected function build(): array
	{
		[$routes, $meta] = Routes::build();
		AttributeRoutes::mergeInto($routes, $meta);
		return [$routes, $meta];
	}
}
