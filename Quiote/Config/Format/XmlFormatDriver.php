<?php
namespace Quiote\Config\Format;

use Quiote\Config\IArrayConfigHandler;
use Quiote\Config\XmlConfigHandler;
use Quiote\Config\XmlConfigParser;

/**
 * Wraps the existing XmlConfigParser pipeline (XInclude, XSD validation,
 * XSL normalization, parent-chain merge -- all untouched, see
 * docs/CONFIG_SYSTEM_REWRITE_PLAN.md phase 1's "what this is NOT") and
 * converts its output to the canonical array via the bound handler's
 * toCanonicalArray(). This is what lets a FormatDriverRegistry treat an
 * existing validators.xml/settings.xml exactly like a PHP-array or YAML
 * source of the same canonical shape.
 *
 * Bound to one handler (and therefore one config type) at construction
 * time -- see FormatDriverRegistry's class docs for why a registry can't
 * mix config types through a single XML driver.
 * @since      1.0.0
 */
final class XmlFormatDriver implements FormatDriverInterface
{
	/**
	 * @param IArrayConfigHandler&XmlConfigHandler $handler
	 * @param string[] $transformations XSL stylesheet paths applied in
	 *        the single-file parse stage, in order (matching how
	 *        config_handlers.xml lists <transformation> entries for this
	 *        config type today).
	 */
	public function __construct(
		private readonly IArrayConfigHandler&XmlConfigHandler $handler,
		private readonly array $transformations = [],
	) {
	}

	public function supports(string $path): bool
	{
		return str_ends_with(strtolower($path), '.xml');
	}

	public function load(string $path, string $environment, ?string $context = null): array
	{
		$document = XmlConfigParser::run(
			$path,
			$environment,
			$context ?? '',
			[
				XmlConfigParser::STAGE_SINGLE => $this->transformations,
				XmlConfigParser::STAGE_COMPILATION => [],
			],
			[
				XmlConfigParser::STAGE_SINGLE => [
					XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [],
					XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [],
				],
				XmlConfigParser::STAGE_COMPILATION => [
					XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [],
					XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [],
				],
			]
		);

		return $this->handler->toCanonicalArray($document);
	}
}
