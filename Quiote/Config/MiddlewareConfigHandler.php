<?php
namespace Quiote\Config;

use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\Schema\Rule;
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
class MiddlewareConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, ISchemaAwareConfigHandler, IPositionAwareConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/middleware/1.1';

	/**
	 * "phase" values per middleware.xsd's enum. Only "class" is required --
	 * everything else means "don't override" when omitted, matching the
	 * XSD's own optional attributes.
	 */
	public function schema(): Rule
	{
		return Rule::listOf(Rule::struct([
			'class' => Rule::phpClass(),
			'phase' => Rule::enumOf([
				'bootstrap', 'pre_routing', 'pre', 'routing',
				'before_action', 'action', 'after_action', 'finalize',
			], nullable: true),
			'priority' => Rule::int(nullable: true),
			'before' => Rule::string(nullable: true),
			'after' => Rule::string(nullable: true),
			'enabled' => Rule::bool(nullable: true),
			'override_framework' => Rule::bool(),
		], required: ['class']));
	}

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
	 * @return array{data: list<array{class: string, phase: ?string, priority: ?int, before: ?string, after: ?string, enabled: ?bool, override_framework: bool}>, positions: array<string, array{file: string, line: int}>}
	 */
	public function toCanonicalArrayWithPositions(XmlConfigDomDocument $document, ElementPositionIndex $positions): array
	{
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'middleware');

		$entries = [];
		$elementPositions = [];
		$index = 0;
		foreach ($document->getConfigurationElements() as $configuration) {
			if (!$configuration->has('use')) {
				continue;
			}

			foreach ($configuration->get('use') as $use) {
				$entries[] = [
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

				$position = $positions->forElement($use);
				if ($position !== null) {
					$elementPositions["[$index].class"] = $position;
				}
				$index++;
			}
		}

		return ['data' => $entries, 'positions' => $elementPositions];
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
