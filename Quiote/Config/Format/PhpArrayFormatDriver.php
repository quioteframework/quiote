<?php
namespace Quiote\Config\Format;

use Quiote\Exception\ConfigurationException;

/**
 * Loads a config source that is itself a plain PHP file returning an
 * array -- the recommended primary format (zero parsing cost beyond
 * opcache, full IDE support, native `parent`/`imports` path resolution via
 * AbstractArrayFormatDriver).
 * @since      1.0.0
 */
final class PhpArrayFormatDriver extends AbstractArrayFormatDriver
{
	public function supports(string $path): bool
	{
		return str_ends_with(strtolower($path), '.php');
	}

	protected function parse(string $path): array
	{
		if (!is_file($path)) {
			throw new ConfigurationException('Config file "' . $path . '" does not exist or is unreadable.');
		}

		$data = require $path;

		if (!is_array($data)) {
			throw new ConfigurationException(sprintf(
				'Config file "%s" must return an array; got %s.',
				$path,
				get_debug_type($data)
			));
		}

		return $data;
	}
}
