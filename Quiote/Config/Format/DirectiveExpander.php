<?php
namespace Quiote\Config\Format;

use Quiote\Util\Toolkit;

/**
 * Applies the same %core.quiote_dir%-style directive expansion and
 * literal-boolean coercion that XML config values get "for free" via
 * XmlConfigDomElement::getLiteralValue() (which runs Toolkit::literalize()
 * on element text by default) -- to PHP-array and YAML config values,
 * which have no XML text-node equivalent to hook that into.
 *
 * Without this, a YAML/PHP config author would have to write
 * `Config::get('core.quiote_dir') . '/foo'` themselves instead of the
 * `%core.quiote_dir%/foo` string every existing XML config already uses,
 * which would make migrating a config file from XML a breaking change in
 * its own right rather than a drop-in format swap.
 * @since      1.0.0
 */
final class DirectiveExpander
{
	/**
	 * Recursively expands every string leaf in $config via
	 * Toolkit::literalize() (directive expansion + "true"/"false"/"yes"/
	 * "no"/etc. coercion to native bool). Non-string leaves (already-typed
	 * YAML/PHP values -- a real bool, int, null) pass through unchanged,
	 * exactly like literalize() already does for non-string input.
	 * @return array A new array; $config is not mutated.
	 */
	public function expand(array $config): array
	{
		$result = [];
		foreach ($config as $key => $value) {
			if (is_array($value)) {
				$result[$key] = $this->expand($value);
			} elseif (is_string($value)) {
				$result[$key] = Toolkit::literalize($value);
			} else {
				$result[$key] = $value;
			}
		}
		return $result;
	}
}
