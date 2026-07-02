<?php
declare(strict_types=1);

namespace Quiote\Routing\Attribute;

use Attribute;

/**
 * Declares a route on an action class. Placed on the class (not a method):
 * Quiote's model is one action class exposing multiple HTTP-verb methods
 * (executeRead/executeWrite/...), the opposite of Symfony MVC's one
 * controller/many route methods, so a class can carry one or more of these.
 * `module`/`action` are deliberately not fields here -- they're derived from
 * the class's location by AttributeRouteScanner, the same way
 * Controller::createActionInstance() derives a class from a module/action
 * pair, just in reverse. See docs/ROUTING_AND_CLI_PLAN.md.
 * @since      1.0.0
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Route
{
	/**
	 * @param string $path Symfony route path, e.g. '/products/{id}'.
	 * @param string|null $name Route name; derived from module+action when omitted.
	 * @param string[] $methods HTTP methods this route accepts; empty means all.
	 * @param array<string,string> $requirements Per-parameter regex requirements.
	 * @param array<string,mixed> $defaults Extra route defaults, merged under module/action.
	 * @param string|null $host Route host pattern.
	 * @param string|null $condition Symfony ExpressionLanguage condition.
	 * @param int $priority Route priority (higher matches first).
	 * @param string|null $outputType Quiote output type this route resolves to.
	 */
	public function __construct(
		public readonly string $path,
		public readonly ?string $name = null,
		public readonly array $methods = [],
		public readonly array $requirements = [],
		public readonly array $defaults = [],
		public readonly ?string $host = null,
		public readonly ?string $condition = null,
		public readonly int $priority = 0,
		public readonly ?string $outputType = null,
	) {
	}
}
