<?php
namespace Quiote\Config\Format;

use Quiote\Config\Format\Php\PhpArrayPositionParser;
use Quiote\Exception\ConfigurationException;

/**
 * Loads a config source that is itself a plain PHP file returning an
 * array -- the recommended primary format (zero parsing cost beyond
 * opcache, full IDE support, native `parent`/`imports` path resolution via
 * AbstractArrayFormatDriver).
 * @since      1.0.0
 */
final class PhpArrayFormatDriver extends AbstractArrayFormatDriver implements PositionAwareFormatDriverInterface
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

	/**
	 * @return array{data: array<string, mixed>, positions: array<string, array{file: string, line: int}>}
	 */
	public function loadWithPositions(string $path, ?string $environment, ?string $context = null): array
	{
		// "parent"/"imports" are stripped from $data by load() (they're
		// directives, not canonical config content) -- strip their
		// positions too, so the position map never claims a position for a
		// key that isn't actually present in the returned data. "imports"
		// is itself a list, so its leaf positions are "imports[0]" etc.,
		// not a bare "imports" key -- filter by prefix, not just an exact key.
		$positions = array_filter(
			PhpArrayPositionParser::parse($path),
			static fn(string $key): bool => $key !== 'parent' && $key !== 'imports' && !str_starts_with($key, 'imports['),
			ARRAY_FILTER_USE_KEY,
		);

		return [
			'data' => $this->load($path, $environment, $context),
			'positions' => $positions,
		];
	}
}
