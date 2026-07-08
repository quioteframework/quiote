<?php
namespace Quiote\Config;

use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;

/**
 * Opt-in, like ISchemaAwareConfigHandler: a handler implements this once it
 * knows how to correlate its own canonical-array key paths back to the
 * elements it read them from. $positions only ever has entries for elements
 * that survived the merge pipeline untouched (see
 * XmlConfigParser::correlatePosition()) -- a handler whose config type has
 * legacy-upgrade <transformation> stylesheets configured (settings,
 * factories, databases, ...) will, in practice, get an empty positions map
 * back for most/all keys; that's the merge pipeline correctly reporting "no
 * reliable line available" rather than a bug in the handler.
 * @since      1.0.0
 */
interface IPositionAwareConfigHandler
{
	/**
	 * @return array{data: array<mixed>, positions: array<string, array{file: string, line: int}>}
	 */
	public function toCanonicalArrayWithPositions(XmlConfigDomDocument $document, ElementPositionIndex $positions): array;
}

?>
