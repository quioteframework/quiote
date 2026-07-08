<?php
declare(strict_types=1);

namespace Quiote\Routing\Compiler;

use Quiote\Config\Config;
use Quiote\Controller\Controller;
use Quiote\Support\Compiler\Diagnostic;
use ReflectionClass;

/**
 * Diagnoses the Action/View/Template triad convention
 * (`Actions/{Action}Action.php` <-> `Views/{Action}{ViewName}View.php` <->
 * `Templates/{Action}{ViewName}.php`) for every action
 * {@see ModuleActionDiscovery} finds, independent of whether the action is
 * ever actually routed to. Three checks, each only attempted once the prior
 * one succeeds (a missing action class means there is nothing to reflect a
 * view name from, etc.):
 *
 * - `MISSING_ACTION_CLASS`: the `Actions/*Action.php` file does not define
 *   the class its own path/namespace convention implies.
 * - `MISSING_VIEW`: `getDefaultViewName()` names a view with no matching
 *   `{Action}{ViewName}View` class and no legacy view file on disk.
 * - `MISSING_TEMPLATE`: the view exists, but at least one of its
 *   `execute()`/`execute{OutputType}()` methods (per
 *   {@see TriadViewResolver::executeMethodsFor()}) has no matching template
 *   file for the extension its output type renders with (per
 *   {@see TriadViewResolver::templateExtensionFor()} -- the app's real
 *   renderer configuration when a `Controller` is supplied, `.php`
 *   otherwise). Convention-only, not a bug: it can only false-flag as
 *   missing, never hide a real gap, so it stays a warning. A specific
 *   `execute*()` method can opt out with
 *   `@quiote-viewmethod-has-no-template` in its own docblock, for methods
 *   whose output type returns content directly and never renders a template
 *   by design (e.g. a JSON-only `executeJson()`) --
 *   {@see TriadViewResolver::declaresNoTemplate()}.
 *
 * `getDefaultViewName()` is read via `newInstanceWithoutConstructor()`
 * ({@see TriadViewResolver}) so no constructor/DI side effects run; an
 * action whose base classes make even this unsafe (an uncatchable fatal, not
 * a Throwable) is simply the one gap this scanner cannot see -- no
 * diagnostic is produced for that action, but no false one either.
 * @since      1.0.0
 */
final class TriadDiagnosticsScanner
{
	public function __construct(
		private readonly TriadViewResolver $views = new TriadViewResolver(),
		private readonly ?Controller $controller = null,
	) {
	}

	/**
	 * @param list<ModuleActionEntry> $entries
	 * @return list<Diagnostic>
	 */
	public function scan(array $entries): array
	{
		$diagnostics = [];
		foreach ($entries as $entry) {
			foreach ($this->diagnoseAction($entry) as $diagnostic) {
				$diagnostics[] = $diagnostic;
			}
		}
		return $diagnostics;
	}

	/**
	 * @return list<Diagnostic>
	 */
	private function diagnoseAction(ModuleActionEntry $entry): array
	{
		$symbol = $entry->module . '.' . $entry->action;

		if (!class_exists($entry->fqcn, false) && !class_exists($entry->fqcn, true)) {
			require_once $entry->file;
		}
		$legacyClass = $entry->legacyClassName();
		if (!class_exists($entry->fqcn, false) && !class_exists($legacyClass, false)) {
			return [new Diagnostic(
				Diagnostic::SEVERITY_ERROR,
				Diagnostic::CODE_MISSING_ACTION_CLASS,
				sprintf('Action file "%s" does not define the expected class "%s" (or legacy "%s").', $entry->file, $entry->fqcn, $legacyClass),
				$entry->file,
				symbol: $symbol,
			)];
		}

		if (class_exists($entry->fqcn, false)) {
			$reflection = new ReflectionClass($entry->fqcn);
		} elseif (class_exists($legacyClass, false)) {
			$reflection = new ReflectionClass($legacyClass);
		} else {
			// Unreachable: the guard above already returned unless one of the two exists.
			return [];
		}
		$viewToken = $this->views->resolveViewToken($reflection);
		if ($viewToken === null) {
			return [];
		}

		$namespacePrefix = Config::getString('core.namespace_prefix', 'App');
		$canonicalViewToken = $this->views->canonicalViewToken($entry, $viewToken);

		if ($this->views->resolveExistingViewFile($entry, $canonicalViewToken, $namespacePrefix) === null) {
			$viewClass = $this->views->viewClassFor($entry, $canonicalViewToken, $namespacePrefix);
			return [new Diagnostic(
				Diagnostic::SEVERITY_WARNING,
				Diagnostic::CODE_MISSING_VIEW,
				sprintf('Action "%s" declares view "%s", but no "%s" class or view file was found.', $symbol, $canonicalViewToken, $viewClass),
				$entry->file,
				symbol: $symbol,
			)];
		}

		$viewClass = $this->views->viewClassFor($entry, $canonicalViewToken, $namespacePrefix);
		if (!class_exists($viewClass)) {
			// Legacy (non-class) view file: no execute*() methods to reflect,
			// so fall back to the single conventional PHP template check.
			$templateFile = $this->views->templateFileFor($entry, $canonicalViewToken);
			if (!is_file($templateFile)) {
				return [new Diagnostic(
					Diagnostic::SEVERITY_WARNING,
					Diagnostic::CODE_MISSING_TEMPLATE,
					sprintf('Action "%s" resolves to view "%s", but its default template "%s" was not found.', $symbol, $canonicalViewToken, $templateFile),
					$entry->file,
					symbol: $symbol,
				)];
			}
			return [];
		}

		$missing = [];
		foreach ($this->views->executeMethodsFor(new ReflectionClass($viewClass)) as $method) {
			if ($this->views->declaresNoTemplate($method)) {
				continue;
			}
			$extension = $this->views->templateExtensionFor($method, $this->controller);
			$templateFile = $this->views->templateFileFor($entry, $canonicalViewToken, $extension);
			if (!is_file($templateFile)) {
				$missing[] = sprintf('%s() -> "%s"', $method->getName(), $templateFile);
			}
		}
		if ($missing !== []) {
			return [new Diagnostic(
				Diagnostic::SEVERITY_WARNING,
				Diagnostic::CODE_MISSING_TEMPLATE,
				sprintf('Action "%s" resolves to view "%s", but its template is missing for: %s.', $symbol, $canonicalViewToken, implode(', ', $missing)),
				$entry->file,
				symbol: $symbol,
			)];
		}

		return [];
	}
}
