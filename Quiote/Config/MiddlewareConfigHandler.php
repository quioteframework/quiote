<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Util\Toolkit;

/**
 * MiddlewareConfigHandler reads a `middleware.{xml,php,yaml,yml}` file --
 * a flat list of `<use>` entries that register app/plugin middleware and/or
 * override the placement or enabled state of any middleware (framework or
 * app) known to `#[Quiote\Middleware\Attribute\Middleware]` scanning.
 *
 * Each entry compiles to a contribution recorded on
 * {@see \Quiote\Middleware\Config\MiddlewareConfigRegistry}, which
 * {@see \Quiote\Middleware\MiddlewarePipeline::doBuild()} merges with
 * attribute-scanned definitions before ordering the pipeline. Fields left
 * unset in an entry (represented as null in the canonical array) don't
 * override anything -- they fall back to the class's own `#[Middleware]`
 * attribute, or framework defaults for a class with none.
 *
 * Canonical schema: list<array{class: string, phase: ?string, priority: ?int,
 * before: ?string, after: ?string, enabled: ?bool, override_framework: bool}>,
 * in document order.
 * @since      1.0.0
 */
class MiddlewareConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/middleware/1.1';

	/**
	 * @throws     \Quiote\Exception\ParseException If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	/**
	 * @return list<array{class: string, phase: ?string, priority: ?int, before: ?string, after: ?string, enabled: ?bool, override_framework: bool}>
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'middleware');

		$entries = [];
		foreach ($document->getConfigurationElements() as $configuration) {
			if (!$configuration->has('use')) {
				continue;
			}

			foreach ($configuration->get('use') as $use) {
				$entries[] = [
					// XSD requires "class"; the (string) cast reflects that guarantee to PHPStan.
					'class' => (string) $use->getAttribute('class'),
					'phase' => $use->hasAttribute('phase') ? $use->getAttribute('phase') : null,
					'priority' => $use->hasAttribute('priority') ? (int) $use->getAttribute('priority') : null,
					'before' => $use->hasAttribute('before') ? $use->getAttribute('before') : null,
					'after' => $use->hasAttribute('after') ? $use->getAttribute('after') : null,
					'enabled' => $use->hasAttribute('enabled')
						? (bool) Toolkit::literalize(strtolower((string) $use->getAttribute('enabled')))
						: null,
					'override_framework' => $use->hasAttribute('override-framework')
						? (bool) Toolkit::literalize(strtolower((string) $use->getAttribute('override-framework')))
						: false,
				];
			}
		}

		return $entries;
	}

	/**
	 * @param list<array{class: string, phase?: ?string, priority?: ?int, before?: ?string, after?: ?string, enabled?: ?bool, override_framework?: bool}> $config
	 *        Hand-authored PHP/YAML sources may omit any field but `class`;
	 *        omitted fields normalize to "don't override" (null), matching
	 *        the XSD's own optional attributes.
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$normalized = array_map(static fn(array $entry): array => [
			'class' => $entry['class'],
			'phase' => $entry['phase'] ?? null,
			'priority' => $entry['priority'] ?? null,
			'before' => $entry['before'] ?? null,
			'after' => $entry['after'] ?? null,
			'enabled' => $entry['enabled'] ?? null,
			'override_framework' => $entry['override_framework'] ?? false,
		], $config);

		$code = [];
		$code[] = '\Quiote\Middleware\Config\MiddlewareConfigRegistry::contribute('
			. var_export($normalized, true) . ', ' . var_export($sourceRef ?? '(unknown)', true) . ');';

		return $this->generate($code, $sourceRef);
	}
}
