<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

use Quiote\Action\Action;
use Quiote\Util\Toolkit;
use ReflectionClass;
use Throwable;

/**
 * Shared Action -> View -> Template resolution for the triad convention
 * (`Actions/{Action}Action.php` <-> `Views/{Action}{ViewName}View.php` <->
 * `Templates/{Action}{ViewName}.php`), used by both
 * {@see TriadDiagnosticsScanner} (which only needs existence) and
 * `Quiote\Introspection\AppIntrospectionCompiler` (which needs the resolved
 * file paths for the introspection artifact), so the naming convention is
 * decoded in exactly one place.
 * @since      1.0.0
 */
final class TriadViewResolver
{
	/**
	 * The view an action *declares* as its default, for triad/diagnostic
	 * purposes -- deliberately narrower than "whatever `getDefaultViewName()`
	 * returns". Most actions never override it, so it silently inherits the
	 * base `Action::getDefaultViewName()`'s `'Input'` constant; treating that
	 * inherited value as a real declaration would flag `MISSING_VIEW` on
	 * every action that just doesn't happen to have an `*Input` view (the
	 * common case, since the real view for most requests comes from the
	 * string an `execute*()` method returns at runtime, which is outside
	 * what static analysis can see here). Only a class that overrides the
	 * method is treated as declaring a real default view.
	 * @param ReflectionClass<object> $reflection
	 */
	public function resolveViewToken(ReflectionClass $reflection): ?string
	{
		if (!$reflection->isInstantiable() || !$reflection->isSubclassOf(Action::class)) {
			return null;
		}
		if (!$reflection->hasMethod('getDefaultViewName')) {
			return null;
		}
		if ($reflection->getMethod('getDefaultViewName')->getDeclaringClass()->getName() === Action::class) {
			return null;
		}
		try {
			$viewToken = $reflection->newInstanceWithoutConstructor()->getDefaultViewName();
		} catch (Throwable) {
			return null;
		}
		return is_string($viewToken) && $viewToken !== '' ? $viewToken : null;
	}

	public function canonicalViewToken(ModuleActionEntry $entry, string $viewToken): string
	{
		$evaluated = Toolkit::evaluateModuleDirective(
			$entry->module,
			'quiote.view.name',
			['actionName' => $entry->action, 'viewName' => $viewToken],
		);
		return Toolkit::canonicalName($evaluated !== '' ? $evaluated : $viewToken);
	}

	public function viewClassFor(ModuleActionEntry $entry, string $canonicalViewToken, string $namespacePrefix): string
	{
		return $namespacePrefix . '\\Modules\\' . $entry->module . '\\Views\\' . str_replace('/', '\\', $canonicalViewToken) . 'View';
	}

	public function legacyViewFileFor(ModuleActionEntry $entry, string $canonicalViewToken): string
	{
		return Toolkit::evaluateModuleDirective(
			$entry->module,
			'quiote.view.path',
			['moduleName' => $entry->module, 'viewName' => $canonicalViewToken],
		);
	}

	public function templateFileFor(ModuleActionEntry $entry, string $canonicalViewToken): string
	{
		$directory = rtrim(Toolkit::evaluateModuleDirective(
			$entry->module,
			'quiote.template.directory',
			['module' => $entry->module, 'moduleName' => $entry->module],
		), '/');
		return $directory . '/' . $canonicalViewToken . '.php';
	}

	/**
	 * Existing view class name, or the legacy view file path if only that
	 * exists, or null if neither does.
	 */
	public function resolveExistingViewFile(ModuleActionEntry $entry, string $canonicalViewToken, string $namespacePrefix): ?string
	{
		$viewClass = $this->viewClassFor($entry, $canonicalViewToken, $namespacePrefix);
		if (class_exists($viewClass)) {
			$file = (new ReflectionClass($viewClass))->getFileName();
			return $file !== false ? $file : null;
		}

		$legacy = $this->legacyViewFileFor($entry, $canonicalViewToken);
		return is_file($legacy) ? $legacy : null;
	}
}
