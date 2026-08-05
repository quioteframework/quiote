<?php
namespace Quiote\Config;

use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\Schema\Rule;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ConfigurationException;
use Quiote\Util\Toolkit;

/**
 * MiddlewareConfigHandler reads a `middleware.{xml,php,yaml,yml}` file --
 * a flat list of `<use>` entries that register app/plugin middleware and/or
 * override the placement or enabled state of any middleware (framework or
 * app) known to `#[Quiote\Middleware\Attribute\Middleware]` scanning.
 *
 * The compiled artifact returns the entry list; {@see apply()} records it as a contribution on
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
class MiddlewareConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, ISchemaAwareConfigHandler, IPositionAwareConfigHandler, IDeclarationConfigHandler
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

		return $this->generate('return ' . var_export($normalized, true) . ';', $sourceRef);
	}

	/**
	 * Record the declared entries as contributions on the registry the pipeline builder reads.
	 *
	 * @param      mixed $declaration The normalized entry list {@see executeArray()} compiles.
	 * @since      4.0.0
	 */
	public function apply(mixed $declaration, string $sourceRef): void
	{
		if (!is_array($declaration)) {
			throw new ConfigurationException(sprintf(
				'The compiled middleware declaration from "%s" must be a list of entries, got %s.',
				$sourceRef,
				get_debug_type($declaration)
			));
		}

		\Quiote\Middleware\Config\MiddlewareConfigRegistry::contribute(
			self::assertEntryList($declaration, $sourceRef),
			$sourceRef
		);
	}

	/**
	 * Narrow a declaration read back from the cache (or from a hand-authored PHP/YAML source) to the
	 * entry list the registry accepts.
	 *
	 * The registry checks that each class exists and implements the middleware interface; what it
	 * cannot check is that the surrounding structure is an entry list at all, so that happens here --
	 * a malformed declaration must name its source rather than surface as a type error deep inside
	 * pipeline construction.
	 *
	 * @param      array<mixed> $declaration
	 * @return     list<array{class: string, phase: ?string, priority: ?int, before: ?string, after: ?string, enabled: ?bool, override_framework: bool}>
	 * @since      4.0.0
	 */
	private static function assertEntryList(array $declaration, string $sourceRef): array
	{
		$entries = [];
		foreach ($declaration as $index => $entry) {
			if (!is_array($entry) || !isset($entry['class']) || !is_string($entry['class'])) {
				throw new ConfigurationException(sprintf(
					'Entry %s of the compiled middleware declaration from "%s" must be an array with a string "class" key.',
					var_export($index, true),
					$sourceRef
				));
			}

			$entries[] = [
				'class' => $entry['class'],
				'phase' => self::nullableString($entry, 'phase', $index, $sourceRef),
				'priority' => self::nullableInt($entry, $index, $sourceRef),
				'before' => self::nullableString($entry, 'before', $index, $sourceRef),
				'after' => self::nullableString($entry, 'after', $index, $sourceRef),
				'enabled' => isset($entry['enabled']) ? (bool) $entry['enabled'] : null,
				'override_framework' => (bool) ($entry['override_framework'] ?? false),
			];
		}

		return $entries;
	}

	/**
	 * @param      array<mixed> $entry
	 * @since      4.0.0
	 */
	private static function nullableString(array $entry, string $key, int|string $index, string $sourceRef): ?string
	{
		$value = $entry[$key] ?? null;
		if ($value === null) {
			return null;
		}
		if (!is_string($value)) {
			throw new ConfigurationException(sprintf(
				'The "%s" field of entry %s in the compiled middleware declaration from "%s" must be a string or null, got %s.',
				$key,
				var_export($index, true),
				$sourceRef,
				get_debug_type($value)
			));
		}

		return $value;
	}

	/**
	 * @param      array<mixed> $entry
	 * @since      4.0.0
	 */
	private static function nullableInt(array $entry, int|string $index, string $sourceRef): ?int
	{
		$value = $entry['priority'] ?? null;
		if ($value === null) {
			return null;
		}
		if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
			throw new ConfigurationException(sprintf(
				'The "priority" field of entry %s in the compiled middleware declaration from "%s" must be an int or null, got %s.',
				var_export($index, true),
				$sourceRef,
				get_debug_type($value)
			));
		}

		return (int) $value;
	}
}
