<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Config\Config;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Validator\Compiler\FluentSourceEmitter;
use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\Compiler\Runtime\ValidatorBuilder;
use Quiote\Validator\Compiler\ValidatorCompiler;
use Quiote\Validator\Compiler\ValidatorSource;
use Quiote\Validator\ArraylengthValidator;
use Quiote\Validator\InarrayValidator;
use Quiote\Validator\RegexValidator;
use Quiote\Validator\StringValidator;
use Quiote\Validator\OroperatorValidator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

class FluentSourceEmitterTest extends UnitTestCase
{
	/**
	 * Writes the emitted source to a temp file, includes it, and applies
	 * the returned closure to a fresh ValidatorBuilder/ValidationManager
	 * pair -- this is the real proof that generated source behaves
	 * correctly, not just that it contains expected substrings.
	 */
	private function runGenerated(string $phpSource, ?string $method = null): ValidationManager
	{
		$file = tempnam(sys_get_temp_dir(), 'fse_test_');
		file_put_contents($file, $phpSource);
		try {
			$registrar = include $file;
		} finally {
			unlink($file);
		}
		$this->assertIsCallable($registrar, "Generated source did not return a callable:\n" . $phpSource);

		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$builder = ValidatorBuilder::on($vm, $this->getContext(), $method);
		$registrar($builder);

		return $vm;
	}

	public function testSimpleStringValidatorUsesFluentFactory(): void
	{
		$node = new ValidatorNode(
			'username_check',
			StringValidator::class,
			['username'],
			'',
			['min' => 3, 'max' => 32, 'trim' => true, 'severity' => 'error', 'required' => true, 'class' => 'string'],
			[],
			[''],
			['username']
		);
		$plan = new ValidatorPlan([$node], 'test://simple-string');

		$emitter = new FluentSourceEmitter();
		$artifact = $emitter->emit($plan);

		$this->assertStringContainsString("\$v->string('username', true)", $artifact->phpSource);
		$this->assertStringContainsString('->minLength(3)', $artifact->phpSource);
		$this->assertStringContainsString('->maxLength(32)', $artifact->phpSource);
		$this->assertStringContainsString('->trim(true)', $artifact->phpSource);
		$this->assertSame([], $emitter->getDiagnostics());

		$vm = $this->runGenerated($artifact->phpSource);
		$this->assertTrue($vm->execute($this->newWebRequest(['username' => 'alice'])));

		$vm2 = $this->runGenerated($artifact->phpSource);
		$this->assertFalse($vm2->execute($this->newWebRequest(['username' => 'al'])));
	}

	public function testEnumValidatorEnforcesAllowlistViaGeneratedSource(): void
	{
		$node = new ValidatorNode(
			'status_check',
			InarrayValidator::class,
			['status'],
			'',
			['values' => ['pending', 'approved', 'rejected'], 'sep' => ',', 'severity' => 'error', 'required' => true, 'class' => 'inarray'],
			[],
			[''],
			['status']
		);
		$plan = new ValidatorPlan([$node], 'test://enum');

		$emitter = new FluentSourceEmitter();
		$artifact = $emitter->emit($plan);
		$this->assertStringContainsString('$v->enum(', $artifact->phpSource);
		$this->assertSame([], $emitter->getDiagnostics());

		$vm = $this->runGenerated($artifact->phpSource);
		$this->assertTrue($vm->execute($this->newWebRequest(['status' => 'approved'])));

		$vm2 = $this->runGenerated($artifact->phpSource);
		$this->assertFalse($vm2->execute($this->newWebRequest(['status' => "'; DROP TABLE users; --"])));
	}

	public function testRegexValidatorGeneratesFluentCall(): void
	{
		$node = new ValidatorNode(
			'fail_param',
			RegexValidator::class,
			['fail'],
			'',
			['pattern' => '/^[01]$/', 'match' => true, 'severity' => 'error', 'required' => false, 'class' => 'regex'],
			[],
			['write'],
			['fail']
		);
		$plan = new ValidatorPlan([$node], 'test://regex');

		$emitter = new FluentSourceEmitter();
		$artifact = $emitter->emit($plan);
		$this->assertStringContainsString('$v->regex(', $artifact->phpSource);
		$this->assertSame([], $emitter->getDiagnostics());

		$vm = $this->runGenerated($artifact->phpSource);
		$this->assertTrue($vm->execute($this->newWebRequest(['fail' => '1'])));

		$vm2 = $this->runGenerated($artifact->phpSource);
		$this->assertFalse($vm2->execute($this->newWebRequest(['fail' => '9'])));
	}

	public function testOperatorGroupNestsChildrenRecursively(): void
	{
		$childA = new ValidatorNode('a', StringValidator::class, ['a'], '', ['required' => false, 'severity' => 'error', 'class' => 'string'], [], [''], ['a']);
		$childB = new ValidatorNode('b', StringValidator::class, ['b'], '', ['required' => false, 'severity' => 'error', 'class' => 'string'], [], [''], ['b']);
		$group = new ValidatorNode('either', OroperatorValidator::class, [], '', ['severity' => 'error', 'class' => 'or'], [], [''], [], [$childA, $childB]);
		$plan = new ValidatorPlan([$group], 'test://group');

		$emitter = new FluentSourceEmitter();
		$artifact = $emitter->emit($plan);
		$this->assertStringContainsString("\$v->group('or', function", $artifact->phpSource);
		$this->assertSame([], $emitter->getDiagnostics());

		$vm = $this->runGenerated($artifact->phpSource);
		$childs = $vm->getChilds();
		$this->assertCount(1, $childs);
		$groupValidator = reset($childs);
		$this->assertInstanceOf(\Quiote\Validator\IValidatorContainer::class, $groupValidator);
		$this->assertCount(2, $groupValidator->getChilds());
	}

	public function testExplicitNameForcesRawFallbackWithDiagnosticButStillWorks(): void
	{
		$node = new ValidatorNode(
			'named_node',
			StringValidator::class,
			['username'],
			'',
			['name' => 'named_node', 'min' => 3, 'severity' => 'error', 'required' => true, 'class' => 'string'],
			[],
			[''],
			['username']
		);
		$plan = new ValidatorPlan([$node], 'test://named');

		$emitter = new FluentSourceEmitter();
		$artifact = $emitter->emit($plan);

		$this->assertStringContainsString('$v->raw(', $artifact->phpSource);
		$diagnostics = $emitter->getDiagnostics();
		$this->assertCount(1, $diagnostics);
		$this->assertSame(Diagnostic::CODE_UNMAPPABLE_PARAMETER, $diagnostics[0]->code);

		$vm = $this->runGenerated($artifact->phpSource);
		$this->assertTrue($vm->execute($this->newWebRequest(['username' => 'alice'])));
		$childs = $vm->getChilds();
		$namedChild = reset($childs);
		$this->assertInstanceOf(Validator::class, $namedChild);
		$this->assertSame('named_node', $namedChild->getName());
	}

	public function testUnmappedValidatorClassFallsBackToRawAndStillWorks(): void
	{
		// ArraylengthValidator has no dedicated fluent factory; must still
		// produce fully correct, executable generated code via raw().
		$node = new ValidatorNode(
			'tags_length',
			ArraylengthValidator::class,
			['tags'],
			'',
			['min' => 1, 'max' => 3, 'severity' => 'error', 'required' => true, 'class' => 'arraylength'],
			[],
			[''],
			['tags']
		);
		$plan = new ValidatorPlan([$node], 'test://arraylength');

		$emitter = new FluentSourceEmitter();
		$artifact = $emitter->emit($plan);
		$this->assertStringContainsString('$v->raw(', $artifact->phpSource);
		$this->assertNotEmpty($emitter->getDiagnostics());

		$vm = $this->runGenerated($artifact->phpSource);
		$this->assertTrue($vm->execute($this->newWebRequest(['tags' => ['a', 'b']])));

		$vm2 = $this->runGenerated($artifact->phpSource);
		$this->assertFalse($vm2->execute($this->newWebRequest(['tags' => []])));
	}

	public function testEmissionIsDeterministicAcrossRuns(): void
	{
		$node = new ValidatorNode('a', StringValidator::class, ['a'], '', ['required' => true, 'severity' => 'error', 'class' => 'string'], [], [''], ['a']);
		$plan = new ValidatorPlan([$node], 'test://determinism');

		$first = (new FluentSourceEmitter())->emit($plan);
		$second = (new FluentSourceEmitter())->emit($plan);

		$this->assertSame($first->phpSource, $second->phpSource);
		$this->assertSame($first->checksum, $second->checksum);
		$this->assertDoesNotMatchRegularExpression('/\b20\d\d-\d\d-\d\d\b/', $first->phpSource);
	}

	public function testEndToEndFromRealXmlSourceThroughCompiler(): void
	{
		$compiler = new ValidatorCompiler();
		$source = new ValidatorSource(Config::getString('core.config_dir') . '/tests/validators_unknown_param.xml', 'test-known-parameter');

		[$plan, $parseDiagnostics] = $compiler->parse($source);
		$this->assertSame([], $parseDiagnostics);

		$artifact = $compiler->emit($plan, new FluentSourceEmitter());
		$vm = $this->runGenerated($artifact->phpSource);

		$this->assertTrue($vm->execute($this->newWebRequest(['username' => 'someone'])));
		$vm2 = $this->runGenerated($artifact->phpSource);
		$this->assertFalse($vm2->execute($this->newWebRequest(['username' => 'x'])));
	}
}
?>
