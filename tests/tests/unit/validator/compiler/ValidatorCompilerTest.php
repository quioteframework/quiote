<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Exception\ConfigurationException;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Support\Compiler\EmittedArtifact;
use Quiote\Validator\Compiler\CompilationResult;
use Quiote\Validator\Compiler\EmitterInterface;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\Compiler\RuntimeDeclarationEmitter;
use Quiote\Validator\Compiler\Runtime\ValidatorDeclarationApplier;
use Quiote\Validator\Compiler\ValidatorCompiler;
use Quiote\Validator\Compiler\ValidatorSource;

class ValidatorCompilerTest extends PhpUnitTestCase
{
	public function testParseBuildsPlanFromRealValidatorSource(): void
	{
		$compiler = new ValidatorCompiler();
		$source = new ValidatorSource(Config::getString('core.module_dir') . '/Method/Validate/MethodHttp.xml', 'test');

		[$plan, $diagnostics] = $compiler->parse($source);

		$this->assertInstanceOf(ValidatorPlan::class, $plan);
		$this->assertSame([], $diagnostics);
		$this->assertCount(1, $plan->nodes);

		$node = $plan->nodes[0];
		$this->assertSame('fail_param', $node->name);
		$this->assertSame('Quiote\Validator\RegexValidator', $node->validatorClass);
		$this->assertSame(['fail'], $node->arguments);
	}

	/**
	 * The "required" attribute is literalized (Toolkit::literalize()) into a
	 * bool at plan-build time; a value that doesn't literalize to a boolean
	 * (e.g. "banana") is a malformed config and must fail loudly instead of
	 * silently propagating a non-bool through the compiled plan.
	 */
	public function testParseRejectsNonBooleanRequiredAttribute(): void
	{
		$compiler = new ValidatorCompiler();
		$source = new ValidatorSource(dirname(__DIR__, 4) . '/fixtures/ValidatorPlanBuilder/invalid_required.xml', 'test');

		$this->expectException(ConfigurationException::class);
		$compiler->parse($source);
	}

	/**
	 * Regression guard: an unnamed validator (no "name" attribute -- common for operator wrappers
	 * like <validator class="not">) gets a synthetic name from Toolkit::uniqid() at plan-build time,
	 * used as the emitted declaration's node name and as any child's "parent" reference. That name
	 * must also land in the node's own `parameters['name']`, or Validator::initialize() -- finding no
	 * "name" parameter -- mints a SECOND, independent uniqid for the runtime instance's own getName(),
	 * which can never match the compile-time name a child references as its parent.
	 * ValidatorDeclarationApplier::apply() must not throw "not a validator declared before it" for a
	 * config shape this ordinary: an unnamed wrapper (no method, so methodless bucket) around a named
	 * child that carries its own method (a different bucket) -- the exact shape of an XML
	 * <validator class="not"> with no method wrapping a method-tagged child.
	 */
	public function testApplyAttachesAMethodTaggedChildToItsUnnamedParent(): void
	{
		$compiler = new ValidatorCompiler();
		$source = new ValidatorSource(
			dirname(__DIR__, 4) . '/fixtures/ValidatorPlanBuilder/unnamed_operator_with_method_tagged_child.xml',
			'test'
		);

		[$plan, ] = $compiler->parse($source);

		$declaration = (new RuntimeDeclarationEmitter())->emit($plan);

		$context = \Quiote\Context::getInstance();
		$vm = $context->getContainer()->get(\Quiote\Validator\ValidationManager::class);
		$vm->clear();

		ValidatorDeclarationApplier::apply($declaration, $vm, 'write', $context, $source->path);

		$children = $vm->getChilds();
		$this->assertCount(1, $children, 'The unnamed "not" wrapper must itself be attached to the manager');
		$notValidator = reset($children);
		$this->assertInstanceOf(\Quiote\Validator\NotoperatorValidator::class, $notValidator);
		$this->assertCount(1, $notValidator->getChilds(), 'Its method-tagged child must be attached to it, not orphaned');
		$this->assertInstanceOf(\Quiote\Validator\StringValidator::class, $notValidator->getChild('child_check'));
	}

	public function testDiscoverDelegatesToLocatorWithDefaultRoots(): void
	{
		$compiler = new ValidatorCompiler();
		$sources = $compiler->discover();

		$this->assertNotEmpty($sources);
		$paths = array_map(fn($s) => basename($s->path), $sources);
		$this->assertContains('MethodHttp.xml', $paths);
	}

	public function testCompileMergesParseAndEmitDiagnostics(): void
	{
		$compiler = new ValidatorCompiler();
		$source = new ValidatorSource(Config::getString('core.module_dir') . '/Method/Validate/MethodHttp.xml', 'test');

		$emitter = new class implements EmitterInterface {
			public function emit(ValidatorPlan $plan): EmittedArtifact
			{
				return EmittedArtifact::fromSource('<?php // stub for ' . count($plan->nodes) . ' node(s)', 'stub.php');
			}
		};

		$result = $compiler->compile($source, $emitter);

		$this->assertInstanceOf(CompilationResult::class, $result);
		$this->assertNotNull($result->artifact);
		$this->assertStringContainsString('1 node(s)', $result->artifact->phpSource);
		$this->assertFalse($result->hasErrors());
	}

	public function testCompilationResultHasErrorsReflectsErrorSeverityDiagnostics(): void
	{
		$artifact = EmittedArtifact::fromSource('<?php', 'x.php');
		$warnOnly = new CompilationResult($artifact, [
			new Diagnostic(Diagnostic::SEVERITY_WARNING, 'X', 'warn', 'y'),
		]);
		$this->assertFalse($warnOnly->hasErrors());

		$withError = new CompilationResult($artifact, [
			new Diagnostic(Diagnostic::SEVERITY_ERROR, 'X', 'boom', 'y'),
		]);
		$this->assertTrue($withError->hasErrors());
	}
}
?>
