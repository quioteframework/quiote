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
	public function schema(): Rule;
}

?>
