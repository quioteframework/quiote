<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

/**
 * One `{Module}/Actions/...Action.php` file found by
 * {@see ModuleActionDiscovery}, before any attempt to load or reflect it.
 * @since      1.0.0
 */
final class ModuleActionEntry
{
	public function __construct(
		public readonly string $module,
		public readonly string $action,
		public readonly string $file,
		public readonly string $fqcn,
		public readonly string $moduleDir,
	) {
	}

	/**
	 * The pre-namespace legacy class name convention
	 * (`Controller::createActionInstance()`'s fallback), e.g.
	 * `sample_SecureSimpleAction` for module "sample", action
	 * "SecureSimple". Some actions -- typically older fixtures/apps -- are
	 * only ever defined this way and never gain a namespaced twin, so any
	 * "does this action class exist" check needs to try both names, not
	 * just {@see fqcn}.
	 */
	public function legacyClassName(): string
	{
		return $this->module . '_' . str_replace('.', '_', $this->action) . 'Action';
	}
}
