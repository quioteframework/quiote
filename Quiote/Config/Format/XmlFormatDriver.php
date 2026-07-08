<?php
namespace Quiote\Config\Format;

use Quiote\Config\IArrayConfigHandler;
use Quiote\Config\IXmlConfigHandler;
use Quiote\Config\XmlConfigParser;

/**
 * Wraps the existing XmlConfigParser pipeline (XInclude, XSD validation,
 * XSL normalization, parent-chain merge -- all untouched, see
 * phase 1's "what this is NOT") and
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
	 * @param IArrayConfigHandler&IXmlConfigHandler $handler
	 * @param string[] $transformations XSL stylesheet paths applied in
	 *        the single-file parse stage, in order (matching how
	 *        config_handlers.xml lists <transformation> entries for this
	 *        config type today).
	 * @param array<string,mixed> $validations The handler's declared XSD /
	 *        RelaxNG / Schematron validations, in the same
	 *        STAGE_SINGLE / STAGE_COMPILATION -> STEP_* shape config_handlers.xml
	 *        produces and the DOM path already receives. Threaded through to
	 *        XmlConfigParser::run() so XML reached via the FormatDriver path
	 *        (e.g. as a `parent`/`imports` reference of a PHP/YAML config) is
	 *        validated against the same schemas as a primary XML file.
	 */
	public function __construct(
		private readonly IArrayConfigHandler&IXmlConfigHandler $handler,
		private readonly array $transformations = [],
		private readonly array $validations = [],
	) {
	}

	public function supports(string $path): bool
	{
		return str_ends_with(strtolower($path), '.xml');
	}

	/**
	 * @return array<string,mixed>
	 */
	public function load(string $path, ?string $environment, ?string $context = null): array
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
				XmlConfigParser::STAGE_SINGLE => $this->stageValidations(XmlConfigParser::STAGE_SINGLE),
				XmlConfigParser::STAGE_COMPILATION => $this->stageValidations(XmlConfigParser::STAGE_COMPILATION),
			]
		);

		return $this->handler->toCanonicalArray($document);
	}

	/**
	 * Extracts one stage's validation map from $this->validations, always
	 * filling in the STEP_TRANSFORMATIONS_BEFORE / _AFTER keys XmlConfigParser
	 * indexes so the structure it receives is well-formed even when a handler
	 * declares no validations (or only some steps) for that stage. Any steps
	 * the handler did populate are preserved as-is; `core.skip_config_validation`
	 * is still honored by XmlConfigParser itself, so a populated map here never
	 * overrides the global skip.
	 * @return array<string,mixed>
	 */
	private function stageValidations(string $stage): array
	{
		$stageValidations = $this->validations[$stage] ?? [];
		if (!is_array($stageValidations)) {
			$stageValidations = [];
		}

		return $stageValidations + [
			XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [],
			XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [],
		];
	}
}
