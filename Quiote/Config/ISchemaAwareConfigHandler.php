<?php
namespace Quiote\Config;

use Quiote\Config\Schema\Rule;

/**
 * Opt-in: a handler implements this once its canonical array shape has a
 * meaningful, hand-authored structural schema. Handlers that don't
 * implement it (e.g. SettingConfigHandler, whose canonical shape is an
 * open, dynamically-keyed flat dot-map with no fixed key set) simply have
 * no array-level schema check available yet -- callers should treat that
 * as "nothing to check", not an error.
 * @since      1.0.0
 */
interface ISchemaAwareConfigHandler
{
	/**
	 * Returns the structural rule the handler's canonical array must satisfy.
	 *
	 * The rule describes the shape produced by the handler's
	 * `toCanonicalArray()`, whatever source format that array came from, so a
	 * PHP-array or YAML config is checked against exactly the same structure as
	 * the XML one.
	 */
	public function schema(): Rule;
}

?>
