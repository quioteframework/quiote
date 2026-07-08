<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

use Quiote\Action\Action;
use Quiote\Controller\Controller;
use Quiote\Util\Toolkit;
use ReflectionClass;
use ReflectionMethod;
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

	public function templateFileFor(ModuleActionEntry $entry, string $canonicalViewToken, string $extension = '.php'): string
	{
		$directory = rtrim(Toolkit::evaluateModuleDirective(
			$entry->module,
			'quiote.template.directory',
			['module' => $entry->module, 'moduleName' => $entry->module],
		), '/');
		return $directory . '/' . $canonicalViewToken . $extension;
	}

	/**
	 * The `execute()`/`execute{OutputType}()` methods a view class declares
	 * (own or inherited from an app-level base view), one per output type it
	 * handles -- mirrors `ActionExecutor`'s own `'execute' . ucfirst($outputType)`
	 * resolution convention. Each is a separate template-triad candidate: an
	 * `executeHtml()` that renders a layout needs a template, an
	 * `executeJson()` that returns `json_encode(...)` directly typically
	 * doesn't, and both can coexist on the same view class.
	 * @param ReflectionClass<object> $view
	 * @return list<ReflectionMethod>
	 */
	public function executeMethodsFor(ReflectionClass $view): array
	{
		$methods = [];
		foreach ($view->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->isAbstract()) {
				continue;
			}
			if ($method->getName() === 'execute' || preg_match('/^execute[A-Z]/', $method->getName()) === 1) {
				$methods[] = $method;
			}
		}
		return $methods;
	}

	/**
	 * The output type name an `execute*()` method is resolved for, or null
	 * for the bare `execute()` method, which stands in for whichever output
	 * type is otherwise in effect (the app's configured default, absent
	 * further context).
	 */
	public function outputTypeNameFor(ReflectionMethod $method): ?string
	{
		if ($method->getName() === 'execute') {
			return null;
		}
		return lcfirst(substr($method->getName(), strlen('execute')));
	}

	/**
	 * The template file extension (leading dot included) that a given
	 * `execute*()` method's output type renders with, resolved from the
	 * app's real, already-initialized output type/renderer configuration
	 * when available. Falls back to the PHP-renderer convention (`.php`)
	 * when no Controller is supplied, or the output type/renderer can't be
	 * resolved (e.g. a name with no configured output type) -- the same
	 * default this check used before per-output-type extensions existed.
	 */
	public function templateExtensionFor(ReflectionMethod $method, ?Controller $controller): string
	{
		if ($controller === null) {
			return '.php';
		}
		try {
			$extension = $controller->getOutputType($this->outputTypeNameFor($method))->getRenderer()?->getDefaultExtension();
		} catch (Throwable) {
			return '.php';
		}
		return $extension !== null && $extension !== '' ? $extension : '.php';
	}

	/**
	 * Whether this specific `execute*()` method opts out of the
	 * `MISSING_TEMPLATE` check via `@quiote-viewmethod-has-no-template` in
	 * its own docblock (inherited from whichever class actually declares it,
	 * same as ordinary method resolution). Escape hatch for a method whose
	 * output type returns content directly (e.g. `executeJson()` returning
	 * `json_encode(...)`) and therefore never renders a template by design
	 * -- {@see TriadDiagnosticsScanner} has no way to see that statically,
	 * so it would otherwise always false-flag a template that will never
	 * exist. Scoped per method, not per class, since one view can freely mix
	 * template-backed and template-less `execute*()` methods.
	 */
	public function declaresNoTemplate(ReflectionMethod $method): bool
	{
		$doc = $method->getDocComment();
		return $doc !== false && str_contains($doc, '@quiote-viewmethod-has-no-template');
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
