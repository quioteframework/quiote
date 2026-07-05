<?php
namespace Quiote\Config\Format;

/**
 * A FormatDriver turns one config source file, in whatever format it
 * understands, into a normalized PHP array -- the same canonical shape a
 * given config handler's array-based execute() method consumes regardless
 * of which driver produced it (see Quiote\Config\IArrayConfigHandler).
 * @since      1.0.0
 */
interface FormatDriverInterface
{
	/**
	 * @param string $environment The active environment name (only
	 *                            meaningful to drivers whose format has a
	 *                            native environment-filtering concept,
	 *                            e.g. XmlFormatDriver; array/YAML drivers
	 *                            ignore it today -- see class docs).
	 * @param string|null $context The active context name, if any.
	 * @return array<string, mixed> The resolved, parent-chain-merged, directive-expanded
	 *               config data.
	 */
	public function load(string $path, string $environment, ?string $context = null): array;

	/**
	 * Whether this driver can handle the given path, based on its
	 * extension. Used by FormatDriverRegistry to pick a driver.
	 */
	public function supports(string $path): bool;
}
