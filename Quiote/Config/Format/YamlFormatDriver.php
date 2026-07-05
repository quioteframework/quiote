<?php
namespace Quiote\Config\Format;

use Quiote\Exception\ConfigurationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads a config source written in YAML, via symfony/yaml. Same
 * `parent`/`imports` resolution as PhpArrayFormatDriver (see
 * AbstractArrayFormatDriver) -- a `parent:` key can point at a YAML file,
 * a PHP-array file, or (for a strangler migration) an XML one.
 * @since      1.0.0
 */
final class YamlFormatDriver extends AbstractArrayFormatDriver
{
	public function supports(string $path): bool
	{
		$lower = strtolower($path);
		return str_ends_with($lower, '.yaml') || str_ends_with($lower, '.yml');
	}

	/**
	 * @return array<int|string, mixed>
	 */
	protected function parse(string $path): array
	{
		if (!is_file($path)) {
			throw new ConfigurationException('Config file "' . $path . '" does not exist or is unreadable.');
		}

		try {
			$data = Yaml::parseFile($path);
		} catch (ParseException $e) {
			throw new ConfigurationException('Failed to parse YAML config file "' . $path . '": ' . $e->getMessage(), 0, $e);
		}

		if ($data === null) {
			// An empty YAML document is a legitimate (if unusual) "no config" file.
			return [];
		}

		if (!is_array($data)) {
			throw new ConfigurationException(sprintf(
				'Config file "%s" must parse to a YAML mapping/sequence; got %s.',
				$path,
				get_debug_type($data)
			));
		}

		return $data;
	}
}
