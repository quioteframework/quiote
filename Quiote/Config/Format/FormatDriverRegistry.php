<?php
namespace Quiote\Config\Format;

use Quiote\Config\IArrayConfigHandler;
use Quiote\Config\IXmlConfigHandler;
use Quiote\Exception\ConfigurationException;

/**
 * Maps a config file's extension to the FormatDriver that understands it,
 * and is itself the thing `parent`/`imports` references are resolved
 * through -- so a PHP-array config can have a YAML parent, a YAML config
 * can import an XML-derived one, etc.
 *
 * A registry is scoped to one config *type* (settings, factories, ...),
 * not global: which canonical array shape a `.xml` file resolves to
 * depends entirely on which IArrayConfigHandler its XmlFormatDriver is
 * bound to (see forHandler()). Mixing driver sets across config types
 * would silently produce the wrong shape for whichever type didn't match.
 * @since      1.0.0
 */
final class FormatDriverRegistry
{
	/** @var FormatDriverInterface[] */
	private array $drivers = [];

	/**
	 * @param FormatDriverInterface[] $drivers Checked in the given order;
	 *        the first whose supports() matches wins. Pass PHP-array
	 *        before YAML before XML to get the priority order used for
	 *        extension-agnostic discovery (see locate()).
	 */
	public function __construct(array $drivers = [])
	{
		foreach ($drivers as $driver) {
			$this->register($driver);
		}
	}

	public function register(FormatDriverInterface $driver): void
	{
		if ($driver instanceof AbstractArrayFormatDriver) {
			$driver->setRegistry($this);
		}
		$this->drivers[] = $driver;
	}

	/**
	 * Convenience assembly for the common case: PHP array + YAML + XML,
	 * all producing the canonical array shape $handler defines, in the
	 * priority order extension-agnostic discovery uses (PHP > YAML > XML).
	 * @param string[] $transformations XSL stylesheets applied to the XML
	 *        path only (see XmlFormatDriver); irrelevant to PHP/YAML.
	 */
	public static function forHandler(IArrayConfigHandler&IXmlConfigHandler $handler, array $transformations = []): self
	{
		return new self([
			new PhpArrayFormatDriver(),
			new YamlFormatDriver(),
			new XmlFormatDriver($handler, $transformations),
		]);
	}

	public function resolve(string $path): FormatDriverInterface
	{
		foreach ($this->drivers as $driver) {
			if ($driver->supports($path)) {
				return $driver;
			}
		}
		throw new ConfigurationException('No FormatDriver registered that supports "' . $path . '".');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function load(string $path, ?string $environment, ?string $context = null): array
	{
		return $this->resolve($path)->load($path, $environment, $context);
	}

	/**
	 * Extension-agnostic handler discovery: given a base path
	 * with no extension (e.g. "%core.config_dir%/settings"), returns the
	 * first candidate that exists on disk, checked in registration order
	 * (PHP > YAML > XML by convention -- see forHandler()). An explicit
	 * extension in the caller's own pattern should bypass this entirely
	 * and be used as-is; this is only for the extension-less case.
	 * @return string|null The resolved, existing path, or null if none of
	 *                      the candidate extensions exist.
	 */
	public function locate(string $basePathWithoutExtension): ?string
	{
		foreach ($this->drivers as $driver) {
			foreach ($this->candidateExtensionsFor($driver) as $extension) {
				$candidate = $basePathWithoutExtension . '.' . $extension;
				if (is_file($candidate)) {
					return $candidate;
				}
			}
		}
		return null;
	}

	/**
	 * @return string[]
	 */
	private function candidateExtensionsFor(FormatDriverInterface $driver): array
	{
		return match (true) {
			$driver instanceof PhpArrayFormatDriver => ['php'],
			$driver instanceof YamlFormatDriver => ['yaml', 'yml'],
			$driver instanceof XmlFormatDriver => ['xml'],
			default => [],
		};
	}
}
