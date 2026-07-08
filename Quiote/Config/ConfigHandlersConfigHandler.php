<?php
namespace Quiote\Config;

use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\Schema\Rule;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\QuioteException;
use Quiote\Util\Toolkit;

/**
 * ConfigHandlersConfigHandler allows you to specify configuration handlers
 * for the application or on a module level.
 *
 * Migrated to IArrayConfigHandler. Canonical schema is exactly
 * the `$handlers` map execute() used to build inline:
 *   ['pattern' => ['class' => ..., 'parameters' => [...], 'transformations' => [...], 'validations' => [...]]]
 * Note this handler is what config_handlers.xml itself compiles through
 * -- it is NOT what the extension-agnostic handler discovery reads (that
 * would be circular); it stays the framework's own bootstrap-time handler
 * registry regardless of what format future handler configs adopt.
 *
 * Middleware enable/disable used to be configured inline here via a
 * reserved `<middleware_config>` block (compiled to a `__middleware_config`
 * array key); that's superseded by `middleware.xml`'s `<use enabled="...">`
 * (see MiddlewareConfigHandler), which also covers registration and
 * ordering in the same place.
 * @since      1.0.0
 * @version    1.0.0
 */
class ConfigHandlersConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, ISchemaAwareConfigHandler, IPositionAwareConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/config_handlers/1.1';

	/**
	 * "transformations"/"validations" are fixed-shape but deeply nested
	 * (stage -> step -> validation-type -> file list) internal bootstrap
	 * data, not something an app author hand-edits key-by-key -- validated
	 * only as "present", not modeled key-by-key here.
	 */
	public function schema(): Rule
	{
		return Rule::dictOf(Rule::struct([
			'class' => Rule::phpClass(nullable: true),
			'parameters' => Rule::mixed(),
			'transformations' => Rule::mixed(),
			'validations' => Rule::mixed(),
		], required: ['class', 'parameters', 'transformations', 'validations']));
	}

	/**
	 * @throws     \Quiote\Exception\UnreadableException If a requested configuration
	 *                                             file does not exist or is not
	 *                                             readable.
	 * @throws     \Quiote\Exception\ParseException If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'config_handlers');

		// init our data arrays
		$handlers = [];

		foreach ($document->getConfigurationElements() as $configuration) {
			if (!$configuration->has('handlers')) {
				continue;
			}

			// let's do our fancy work
			foreach ($configuration->get('handlers') as $handler) {
				// XSD requires "pattern"; the (string) cast reflects that guarantee to PHPStan.
				$pattern = (string) $handler->getAttribute('pattern');

				$category = Toolkit::normalizePath((string) Toolkit::expandDirectives($pattern));

				$class = $handler->getAttribute('class');

				$transformations = [
					XmlConfigParser::STAGE_SINGLE => [],
					XmlConfigParser::STAGE_COMPILATION => [],
				];
				if ($handler->has('transformations')) {
					foreach ($handler->get('transformations') as $transformation) {
						$path = (string) Toolkit::literalize($transformation->getValue());
						$for = (string) $transformation->getAttribute('for', XmlConfigParser::STAGE_SINGLE);
						$transformations[$for][] = $path;
					}
				}

				$validations = [
					XmlConfigParser::STAGE_SINGLE => [
						XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [
							XmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							XmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
						XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [
							XmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							XmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
					],
					XmlConfigParser::STAGE_COMPILATION => [
						XmlConfigParser::STEP_TRANSFORMATIONS_BEFORE => [
							XmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							XmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
						XmlConfigParser::STEP_TRANSFORMATIONS_AFTER => [
							XmlConfigParser::VALIDATION_TYPE_RELAXNG => [
							],
							XmlConfigParser::VALIDATION_TYPE_SCHEMATRON => [
							],
							XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA => [
							],
						],
					],
				];
				if ($handler->has('validations')) {
					foreach ($handler->get('validations') as $validation) {
						$path = (string) Toolkit::literalize($validation->getValue());
						if (!$validation->hasAttribute('type')) {
							$type = $this->guessValidationType($path);
						} else {
							$type = (string) $validation->getAttribute('type');
						}
						$for = (string) $validation->getAttribute('for', XmlConfigParser::STAGE_SINGLE);
						$step = (string) $validation->getAttribute('step', XmlConfigParser::STEP_TRANSFORMATIONS_AFTER);
						$validations[$for][$step][$type][] = $path;
					}
				}

				$handlers[$category] ??= [
						'parameters' => [],
						];
				$handlers[$category] = [
					'class' => $class,
					'parameters' => $handler->getQuioteParameters($handlers[$category]['parameters']),
					'transformations' => $transformations,
					'validations' => $validations,
				];
			}
		}

		return $handlers;
	}

	/**
	 * @return array{data: array<string, mixed>, positions: array<string, array{file: string, line: int}>}
	 */
	public function toCanonicalArrayWithPositions(XmlConfigDomDocument $document, ElementPositionIndex $positions): array
	{
		$data = $this->toCanonicalArray($document);
		$elementPositions = [];

		foreach ($document->getConfigurationElements() as $configuration) {
			if (!$configuration->has('handlers')) {
				continue;
			}

			foreach ($configuration->get('handlers') as $handler) {
				$pattern = (string) $handler->getAttribute('pattern');
				$category = Toolkit::normalizePath((string) Toolkit::expandDirectives($pattern));

				$position = $positions->forElement($handler);
				if ($position !== null) {
					$elementPositions["{$category}.class"] = $position;
				}
			}
		}

		return ['data' => $data, 'positions' => $elementPositions];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$data = ['return ' . var_export($config, true)];

		return $this->generate($data, $sourceRef);
	}

	/**
	 * Convenience method to quickly guess the type of a validation file using its
	 * file extension.
	 * @param      string $path The path to the file.
	 * @return     string An XmlConfigParser::VALIDATION_TYPE_* const value.
	 * @throws     \Quiote\Exception\QuioteException If the type could not be determined.
	 * @since      1.0.0
	 */
	protected function guessValidationType($path)
	{
		return match (pathinfo((string) $path, PATHINFO_EXTENSION)) {
            'rng' => XmlConfigParser::VALIDATION_TYPE_RELAXNG,
            'rnc' => XmlConfigParser::VALIDATION_TYPE_RELAXNG,
            'sch' => XmlConfigParser::VALIDATION_TYPE_SCHEMATRON,
            'xsd' => XmlConfigParser::VALIDATION_TYPE_XMLSCHEMA,
            default => throw new QuioteException(sprintf('Could not determine validation type for file "%s"', $path)),
        };
	}
}

?>
