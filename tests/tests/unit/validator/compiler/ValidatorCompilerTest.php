<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Support\Compiler\EmittedArtifact;
use Quiote\Validator\Compiler\CompilationResult;
use Quiote\Validator\Compiler\EmitterInterface;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\Compiler\ValidatorCompiler;
use Quiote\Validator\Compiler\ValidatorSource;

class ValidatorCompilerTest extends PhpUnitTestCase
{
	public function testParseBuildsPlanFromRealValidatorSource()
	{
		$compiler = new ValidatorCompiler();
		$source = new ValidatorSource(Config::get('core.module_dir') . '/Method/Validate/MethodHttp.xml', 'test');

		[$plan, $diagnostics] = $compiler->parse($source);

		$this->assertInstanceOf(ValidatorPlan::class, $plan);
		$this->assertSame([], $diagnostics);
		$this->assertCount(1, $plan->nodes);

		$node = $plan->nodes[0];
		$this->assertSame('fail_param', $node->name);
		$this->assertSame('Quiote\Validator\RegexValidator', $node->validatorClass);
		$this->assertSame(['fail'], $node->arguments);
	}

	public function testDiscoverDelegatesToLocatorWithDefaultRoots()
	{
		$compiler = new ValidatorCompiler();
		$sources = $compiler->discover();

		$this->assertNotEmpty($sources);
		$paths = array_map(fn($s) => basename($s->path), $sources);
		$this->assertContains('MethodHttp.xml', $paths);
	}

	public function testCompileMergesParseAndEmitDiagnostics()
	{
		$compiler = new ValidatorCompiler();
		$source = new ValidatorSource(Config::get('core.module_dir') . '/Method/Validate/MethodHttp.xml', 'test');

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

	public function testCompilationResultHasErrorsReflectsErrorSeverityDiagnostics()
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
