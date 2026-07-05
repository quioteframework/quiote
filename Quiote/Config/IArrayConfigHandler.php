<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;

/**
 * A ConfigHandler that has been migrated to the array-based contract
 * (phase 2): its actual compilation logic (executeArray()) consumes a
 * plain, canonical array instead of walking a
 * DOM directly, so the same logic works whether that array came from XML,
 * a PHP-array file, or YAML.
 *
 * toCanonicalArray() is the one XML-specific piece every implementation
 * still needs: it is exactly the DOM-walking logic the handler's old
 * execute(XmlConfigDomDocument) used to do inline, now returning the
 * array instead of generating code from it directly. XmlConfigHandler's
 * execute() calls it and feeds the result into executeArray(), so the XML
 * entrypoint's behavior is unchanged; XmlFormatDriver calls it too, for
 * non-legacy XML loading through a FormatDriverRegistry.
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
	 * @return string Compiled PHP code, exactly as IXmlConfigHandler::execute()
	 *                already returns.
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string;
}
