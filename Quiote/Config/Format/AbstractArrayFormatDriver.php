<?php
namespace Quiote\Config\Format;

use LogicException;
use Quiote\Exception\ConfigurationException;
use Quiote\Util\Toolkit;

/**
 * Shared parent-chain and imports resolution for array-shaped formats
 * (PHP arrays, YAML). Each format only has to implement parse(): raw
 * array in, everything else -- directive expansion, `imports`, `parent`
 * -- behaves identically regardless of which format produced the array.
 *
 * Unlike XmlFormatDriver, $environment/$context are not applied here:
 * PHP-array/YAML files have no native equivalent of XML's
 * `<ae:configuration environment="...">` filtering. A config author who
 * needs environment-conditional values in a PHP-array file can already
 * express that directly (`Config::get('core.environment') === 'test'`
 * inside the returned array's construction) -- that's a deliberate scope
 * limit, not an oversight; see docs/CONFIG_SYSTEM_REWRITE_PLAN.md phase 1.
 * @since      1.0.0
 */
abstract class AbstractArrayFormatDriver implements FormatDriverInterface
{
	private ?FormatDriverRegistry $registry = null;

	public function __construct(
		private readonly ArrayMergeStrategy $merger = new ArrayMergeStrategy(),
		private readonly DirectiveExpander $expander = new DirectiveExpander(),
	) {
	}

	/**
	 * Called by FormatDriverRegistry when this driver is registered, so
	 * `parent`/`imports` references can be resolved through any
	 * registered format, not just this driver's own.
	 */
	public function setRegistry(FormatDriverRegistry $registry): void
	{
		$this->registry = $registry;
	}

	/**
	 * @return array The raw, un-expanded array as read from $path (e.g.
	 *               the value `require`d from a PHP file, or the parsed
	 *               YAML document). May contain 'parent' and/or 'imports'
	 *               keys, which load() strips before returning.
	 */
	abstract protected function parse(string $path): array;

	public function load(string $path, string $environment, ?string $context = null): array
	{
		$raw = $this->parse($path);

		$importPaths = $raw['imports'] ?? [];
		unset($raw['imports']);
		$parentRef = $raw['parent'] ?? null;
		unset($raw['parent']);

		$own = $this->expander->expand($raw);

		foreach ($importPaths as $importPath) {
			$imported = $this->loadReference((string) $importPath, $path, $environment, $context);
			$own = $this->merger->merge($imported, $own);
		}

		if ($parentRef === null) {
			return $own;
		}

		$parentData = $this->loadReference((string) $parentRef, $path, $environment, $context);
		return $this->merger->merge($parentData, $own);
	}

	private function loadReference(string $reference, string $fromPath, string $environment, ?string $context): array
	{
		$registry = $this->registry ?? throw new LogicException(
			static::class . ' cannot resolve "' . $reference . '" without a FormatDriverRegistry (see setRegistry()).'
		);

		$resolved = Toolkit::expandDirectives($reference);
		if (!Toolkit::isPathAbsolute($resolved)) {
			$resolved = dirname($fromPath) . '/' . $resolved;
		}
		if (!is_file($resolved)) {
			throw new ConfigurationException('Referenced config file "' . $resolved . '" (from "' . $fromPath . '") does not exist or is unreadable.');
		}

		return $registry->load($resolved, $environment, $context);
	}
}
