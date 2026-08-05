<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Util\DOM\XmlConfigDomElement;
use Quiote\Util\Inflector;
use Quiote\Util\Toolkit;

/**
 * ReturnArrayConfigHandler allows you to retrieve the contents of a config
 * file as an array.
 * Assumes that the content elements are in no XML namespace; if you want to use
 * an XML namespace for your elements, define the namespace URI using the
 * "namespace_uri" parameter.
 *
 * Migrated to IArrayConfigHandler (phase 2). This handler's whole purpose
 * is "turn a config file into a
 * plain array" -- for XML that means the recursive convertToArray() walk
 * below; for a PHP/YAML source the canonical array *is* the source (there
 * is nothing left to convert), so toCanonicalArray() and executeArray()
 * are a near-trivial split here.
 *
 * Deliberately does not implement ISchemaAwareConfigHandler: its whole
 * point is arbitrary, app-defined XML-to-array conversion driven by
 * caller-supplied parameters (id_attribute/value_key/force_array_values/
 * attribute_prefix), so there is no fixed canonical shape to describe --
 * same reasoning as SettingConfigHandler's open, dynamically-keyed shape.
 * @since      1.0.0
 * @version    1.0.0
 */
class ReturnArrayConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	/**
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): mixed
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		$document->setDefaultNamespace($this->getStringParameter('namespace_uri', ''));

		$data = [];
		foreach ($document->getConfigurationElements() as $cfg) {
			$data = array_merge($data, $this->convertToArray($cfg, true));
		}

		return $data;
	}

	public function executeArray(array $config, ?string $sourceRef = null): mixed
	{
		return $config;
	}

	/**
	 * Retrieve a string-valued handler parameter.
	 * @throws     \Quiote\Exception\ConfigurationException If the parameter is set but isn't a string.
	 * @since      1.0.0
	 */
	private function getStringParameter(string $name, string $default): string
	{
		$value = $this->getParameter($name, $default);
		if (!is_string($value)) {
			throw new \Quiote\Exception\ConfigurationException(sprintf(
				'The "%s" parameter of %s must be a string, got %s.',
				$name,
				static::class,
				get_debug_type($value)
			));
		}
		return $value;
	}

	/**
	 * Retrieve a bool-valued handler parameter.
	 * @throws     \Quiote\Exception\ConfigurationException If the parameter is set but isn't a bool.
	 * @since      1.0.0
	 */
	private function getBoolParameter(string $name, bool $default): bool
	{
		$value = $this->getParameter($name, $default);
		if (!is_bool($value)) {
			throw new \Quiote\Exception\ConfigurationException(sprintf(
				'The "%s" parameter of %s must be a bool, got %s.',
				$name,
				static::class,
				get_debug_type($value)
			));
		}
		return $value;
	}

	/**
	 * Assign a converted child's value to $data, optionally nested one level
	 * deep under $bucketKey (the pluralized container key for repeated/duplicate
	 * child element names).
	 * @param      array<int|string, mixed> $data
	 * @since      1.0.0
	 */
	private function setConvertedChild(array &$data, ?string $bucketKey, int|string $key, mixed $value): void
	{
		if ($bucketKey === null) {
			$data[$key] = $value;
			return;
		}
		$bucket = is_array($data[$bucketKey] ?? null) ? $data[$bucketKey] : [];
		$bucket[$key] = $value;
		$data[$bucketKey] = $bucket;
	}

	/**
	 * Append a converted child's value to $data, optionally nested one level
	 * deep under $bucketKey.
	 * @param      array<int|string, mixed> $data
	 * @since      1.0.0
	 */
	private function appendConvertedChild(array &$data, ?string $bucketKey, mixed $value): void
	{
		if ($bucketKey === null) {
			$data[] = $value;
			return;
		}
		$bucket = is_array($data[$bucketKey] ?? null) ? $data[$bucketKey] : [];
		$bucket[] = $value;
		$data[$bucketKey] = $bucket;
	}

	/**
	 * Converts an XmlConfigDomElement into an array.
	 * A top-level element always yields an array; a non-top-level leaf element
	 * with no attributes yields its own scalar value directly instead of being
	 * wrapped in a single-entry array.
	 * @param      XmlConfigDomElement $item The configuration element to convert.
	 * @param      bool                     $topLevel Whether this is a top level element.
	 * @return     array<int|string, mixed>|bool|int|float|string The configuration
	 *                   values as an array, or the element's own scalar value when
	 *                   it is a leaf node with no attributes.
	 * @phpstan-return ($topLevel is true ? array<int|string, mixed> : array<int|string, mixed>|bool|int|float|string)
	 * @since      1.0.0
	 */
	protected function convertToArray(XmlConfigDomElement $item, bool $topLevel = false)
	{
		$idAttribute = $this->getStringParameter('id_attribute', 'name');
		$valueKey = $this->getStringParameter('value_key', 'value');
		$forceArrayValues = $this->getBoolParameter('force_array_values', false);
		$attributePrefix = $this->getStringParameter('attribute_prefix', '');
		$literalize = $this->getBoolParameter('literalize', true);

		$singularParentName = Inflector::singularize($item->getName());

		$data = [];

		$attribs = $item->getAttributes();
		$numAttribs = count($attribs);
		if ($idAttribute !== '' && $item->hasAttribute($idAttribute)) {
			$numAttribs--;
		}

		foreach ($item->getAttributes() as $name => $value) {
			if (($topLevel && in_array($name, ['context', 'environment'])) || $name == $idAttribute) {
				continue;
			}

			$literalValue = $literalize ? Toolkit::literalize($value) : $value;

			if (!isset($data[$name])) {
				$data[$attributePrefix . $name] = $literalValue;
			}
		}

		$childElementCount = $item->ownerDocument->getXpath()->evaluate(sprintf('count(*[namespace-uri() = "%s"])', $item->ownerDocument->getDefaultNamespaceUri()), $item);
		if (!is_float($childElementCount) && !is_int($childElementCount) && !is_string($childElementCount)) {
			throw new \Quiote\Exception\ConfigurationException(sprintf(
				'Expected the "count(...)" XPath expression to evaluate to a number, got %s.',
				get_debug_type($childElementCount)
			));
		}

		if (!(int) $childElementCount) {
			if ($literalize) {
				$val = $item->getLiteralValue();
			} else {
				$val = $item->getValue();
			}

			if ($val === null) {
				$val = '';
			}

			if (!$topLevel && ($numAttribs || $forceArrayValues)) {
				$data[$valueKey] = $val;
			} elseif (!$topLevel) {
				return $val;
			}

		} else {
			$names = [];
			// The xpath query above only ever selects element nodes, and
			// registerNodeClass() guarantees those are always XmlConfigDomElement,
			// never a vanilla DOMNode.
			$children = $item->ownerDocument->query(sprintf('*[namespace-uri() = "%s"]', $item->ownerDocument->getDefaultNamespaceUri()), $item);
			foreach ($children as $child) {
				/** @var XmlConfigDomElement $child */
				$names[] = $child->getName();
			}
			$dupes = [];
			foreach (array_unique(array_diff_assoc($names, array_unique($names))) as $name) {
				$dupes[] = $name;
			}
			foreach ($children as $child) {
				/** @var XmlConfigDomElement $child */
				$hasId = ($idAttribute !== '' && $child->hasAttribute($idAttribute));
				$isDupe = in_array($child->getName(), $dupes);
				$hasParent = $child->getName() == $singularParentName && $item->getName() != $singularParentName;

				$bucketKey = null;
				if (($hasId || $isDupe) && !$hasParent) {
					// it's one of multiple tags in this level without the respective plural form as the parent node
					$bucketKey = Inflector::pluralize($child->getName());
					if (!isset($data[$bucketKey])) {
						$data[$bucketKey] = [];
					}
					$hasParent = true;
				}

				$childValue = $this->convertToArray($child);

				if ($hasId) {
					$childKey = $child->getAttribute($idAttribute) ?? '';
					if ($literalize) {
						// no literalize, just constants!
						$childKey = Toolkit::expandDirectives($childKey) ?? $childKey;
					}
					$this->setConvertedChild($data, $bucketKey, $childKey, $childValue);
				} elseif ($hasParent) {
					$this->appendConvertedChild($data, $bucketKey, $childValue);
				} else {
					$this->setConvertedChild($data, $bucketKey, $child->getName(), $childValue);
				}
			}
		}

		return $data;
	}
}
?>
