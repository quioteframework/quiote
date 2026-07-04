<?php
namespace Quiote\Validator\Compiler;

use Quiote\Config\Config;
use Quiote\Config\XmlConfigParser;
use Quiote\Config\ValidatorConfigHandler;
use Quiote\Support\Compiler\EmittedArtifact;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;

/**
 * Public entrypoint for validator compilation, independent of any CLI.
 * A future `quiote compile validators` command is expected to be a thin
 * wrapper: discover()/compile() here, ArtifactWriter (or a --check
 * comparison) for output, print diagnostics, choose an exit code.
 * @since      1.0.0
 */
class ValidatorCompiler
{
	/**
	 * @param iterable<string> $roots Glob patterns; defaults to
	 *                                ValidatorSourceLocator::defaultRoots()
	 *                                when omitted.
	 * @return ValidatorSource[]
	 */
	public function discover(?iterable $roots = null): array
	{
		$locator = new ValidatorSourceLocator();
		return $locator->discover($roots ?? ValidatorSourceLocator::defaultRoots());
	}

	/**
	 * Parses a validator source into its format-independent IR.
	 * @return array{0: ValidatorPlan, 1: Diagnostic[]} The plan and every
	 *         diagnostic recorded while building it (empty in 'throw' mode
	 *         unless the source is clean).
	 */
	public function parse(ValidatorSource $source): array
	{
		$document = XmlConfigParser::run(
			$source->path,
			$source->environment ?? Config::get('core.environment'),
			'',
			[
				XmlConfigParser::STAGE_SINGLE => [Config::get('core.quiote_dir') . '/Config/xsl/validators.xsl'],
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

		$builder = new ValidatorPlanBuilder();
		$plan = $builder->build($document, ValidatorConfigHandler::XML_NAMESPACE);

		return [$plan, $builder->getDiagnostics()];
	}

	public function emit(ValidatorPlan $plan, EmitterInterface $emitter): EmittedArtifact
	{
		return $emitter->emit($plan);
	}

	/**
	 * Convenience wrapper: parse() + emit() in one call, with diagnostics
	 * from both stages merged. In 'throw' mode a ConfigurationException
	 * from parse() propagates as normal -- compile() only suppresses
	 * nothing; it exists purely to save the caller two lines.
	 */
	public function compile(ValidatorSource $source, EmitterInterface $emitter): CompilationResult
	{
		[$plan, $diagnostics] = $this->parse($source);
		$artifact = $this->emit($plan, $emitter);

		return new CompilationResult($artifact, $diagnostics);
	}
}
