<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;

/**
 * A ConfigHandler on the array-based contract: its compilation logic (executeArray()) consumes a
 * plain, canonical array instead of walking a DOM, so the same logic works whether that array came
 * from XML, a PHP-array file, or YAML.
 *
 * toCanonicalArray() is the one XML-specific piece every implementation needs: the DOM-walking logic
 * that produces the canonical array. XmlConfigHandler's execute() calls it and feeds the result into
 * executeArray(); XmlFormatDriver calls it too, for non-legacy XML loading through a
 * FormatDriverRegistry.
 * @since      1.0.0
 */
interface IArrayConfigHandler
{
	/**
	 * @return array<mixed> The canonical array shape this handler's config type
	 *               uses -- see the concrete handler's own docblock (e.g.
	 *               SettingConfigHandler) for exactly what that shape is.
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array;

	/**
	 * @param array<mixed> $config The canonical config array, matching the
	 *                    shape returned by toCanonicalArray().
	 * @param string|null $sourceRef Origin reference for the compiled
	 *                    cache file's header comment (a file path for any
	 *                    format; XML's is $document->documentURI).
	 * @return mixed The declaration to be cached, exactly as IXmlConfigHandler::execute() returns.
	 */
	public function executeArray(array $config, ?string $sourceRef = null): mixed;
}
