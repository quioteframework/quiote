<?php

use Quiote\Exception\ConfigurationException;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\Compiler\Runtime\ValidatorDeclarationApplier;
use Quiote\Validator\Compiler\RuntimeDeclarationEmitter;
use Quiote\Validator\OroperatorValidator;
use Quiote\Validator\RegexValidator;
use Quiote\Validator\StringValidator;
use Quiote\Validator\ValidationManager;

/**
 * A compiled validator config declares its validators; the applier builds them. Together these are
 * what used to be a generated string of `new X(); ->initialize(); ->addChild()` statements executed
 * in the caller's scope -- so registration is asserted here directly.
 */
class ValidatorDeclarationTest extends UnitTestCase
{
	/**
	 * @param ValidatorNode[] $nodes
	 * @return array<string, mixed>
	 */
	private function declare(array $nodes): array
	{
		return (new RuntimeDeclarationEmitter())->emit(new ValidatorPlan($nodes, 'test://declaration'));
	}

	/**
	 * One bucket of an emitted declaration, narrowed: emit() promises array<string, mixed>, since the
	 * shape is the artifact's business rather than a compile-time contract.
	 * @param array<string, mixed> $declaration
	 * @return array<string, mixed>
	 */
	private function bucketOf(array $declaration, string $key = ''): array
	{
		self::assertIsArray($declaration['buckets']);
		self::assertArrayHasKey($key, $declaration['buckets']);
		$bucket = $declaration['buckets'][$key];
		self::assertIsArray($bucket);

		return $bucket;
	}

	private function applyTo(mixed $declaration, string $method = ''): ValidationManager
	{
		$vm = $this->getContext()->createInstanceFor('validation_manager');
		self::assertInstanceOf(ValidationManager::class, $vm);
		ValidatorDeclarationApplier::apply($declaration, $vm, $method, $this->getContext(), 'test://declaration');

		return $vm;
	}

	private function stringNode(string $name, string $argument, string $method = ''): ValidatorNode
	{
		return new ValidatorNode(
			$name,
			StringValidator::class,
			[$argument],
			'',
			['min' => 3, 'severity' => 'error', 'required' => true, 'class' => 'string', 'name' => $name],
			['' => 'too short'],
			[$method],
			[$argument]
		);
	}

	public function testADeclaredValidatorIsBuiltAndRegistered(): void
	{
		$vm = $this->applyTo($this->declare([$this->stringNode('username_check', 'username')]));

		$validator = $vm->getChild('username_check');
		$this->assertInstanceOf(StringValidator::class, $validator);
		$this->assertSame(3, $validator->getParameter('min'));
		$this->assertSame($this->getContext(), $validator->getContext());
	}

	/**
	 * The strict-mode whitelist: reading an undeclared parameter throws, so declaring "username" is
	 * what makes the staged value readable at all.
	 */
	public function testDeclaredParametersAreWhitelistedOnTheRequest(): void
	{
		$declaration = $this->declare([$this->stringNode('username_check', 'username')]);
		$this->assertSame(['username'], $this->bucketOf($declaration)['declaredParameters']);

		$context = $this->getContext();
		$original = $context->getRequest();
		try {
			$context->setRequest($original->setUnvalidatedParameter('username', 'ada'));
			$vm = $this->applyTo($declaration);
			$this->assertSame('ada', $vm->getContext()->getRequest()->getParameter('username'));
		} finally {
			$context->setRequest($original);
		}
	}

	public function testANestedValidatorIsAttachedToItsDeclaredParent(): void
	{
		$child = $this->stringNode('or_child', 'nickname');
		$parent = new ValidatorNode(
			'toplevel_or',
			OroperatorValidator::class,
			['nickname'],
			'',
			['severity' => 'error', 'required' => true, 'name' => 'toplevel_or'],
			[],
			[''],
			[],
			[$child]
		);

		$declaration = $this->declare([$parent]);
		$validators = $this->bucketOf($declaration)['validators'];
		self::assertIsArray($validators);
		$this->assertSame([null, 'toplevel_or'], array_column($validators, 'parent'));

		$vm = $this->applyTo($declaration);
		$container = $vm->getChild('toplevel_or');
		$this->assertInstanceOf(OroperatorValidator::class, $container);
		$this->assertInstanceOf(StringValidator::class, $container->getChild('or_child'));
	}

	/**
	 * A validator carrying method="write" belongs to the "write" bucket, which is only applied for a
	 * matching request method -- the condition the compiled artifact used to emit as an if() block.
	 */
	public function testOnlyTheMatchingMethodBucketIsApplied(): void
	{
		$declaration = $this->declare([
			$this->stringNode('always', 'username'),
			$this->stringNode('write_only', 'nickname', 'write'),
		]);

		$read = $this->applyTo($declaration, 'read');
		$this->assertSame(['always'], array_keys($read->getChilds()));

		$write = $this->applyTo($declaration, 'write');
		$this->assertSame(['always', 'write_only'], array_keys($write->getChilds()));
	}

	public function testAMethodlessTokenAppliesTheUnconditionalBucketExactlyOnce(): void
	{
		$vm = $this->applyTo($this->declare([$this->stringNode('always', 'username')]), '');

		$this->assertSame(['always'], array_keys($vm->getChilds()));
	}

	public function testAnEmptyPlanDeclaresAnEmptyUnconditionalBucket(): void
	{
		$declaration = $this->declare([]);

		$this->assertSame(['' => ['declaredParameters' => [], 'validators' => []]], $declaration['buckets']);
		$this->assertSame([], $this->applyTo($declaration)->getChilds());
	}

	public function testDeclaredParameterNamesAreDedupedAndSorted(): void
	{
		$declaration = $this->declare([
			$this->stringNode('b_check', 'beta'),
			$this->stringNode('a_check', 'alpha'),
			$this->stringNode('another_beta_check', 'beta'),
		]);

		$this->assertSame(['alpha', 'beta'], $this->bucketOf($declaration)['declaredParameters']);
	}

	// ---------------------------------------------------------------
	// The applier is a trust boundary: the declaration arrives from a cache entry or a hand-authored
	// file, so a malformed one must name its source rather than fail somewhere downstream.
	// ---------------------------------------------------------------

	public function testApplyRejectsADeclarationWithoutBuckets(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/must be an array with a "buckets" key/');
		$this->applyTo(['validators' => []]);
	}

	public function testApplyRejectsANonArrayBucket(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/Bucket "" .* must be an array/');
		$this->applyTo(['buckets' => ['' => 'nope']]);
	}

	public function testApplyRejectsAValidatorClassThatIsNotAValidator(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/which is not a Quiote.Validator.Validator/');
		$this->applyTo(['buckets' => ['' => ['validators' => [['name' => 'x', 'class' => \stdClass::class]]]]]);
	}

	public function testApplyRejectsAnUnknownParent(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/attaches to "ghost", which is not a validator declared before it/');
		$this->applyTo(['buckets' => ['' => ['validators' => [[
			'name' => 'orphan',
			'class' => RegexValidator::class,
			'parameters' => ['name' => 'orphan', 'pattern' => '/^x$/'],
			'parent' => 'ghost',
		]]]]]);
	}

	public function testApplyRejectsAParentThatCannotHoldChildren(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/cannot hold children/');
		$this->applyTo(['buckets' => ['' => ['validators' => [
			[
				'name' => 'leaf',
				'class' => RegexValidator::class,
				'parameters' => ['name' => 'leaf', 'pattern' => '/^x$/'],
				'parent' => null,
			],
			[
				'name' => 'nested',
				'class' => RegexValidator::class,
				'parameters' => ['name' => 'nested', 'pattern' => '/^y$/'],
				'parent' => 'leaf',
			],
		]]]]);
	}

	public function testApplyRejectsANonStringDeclaredParameterName(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/declared parameter name .* must be a string/');
		$this->applyTo(['buckets' => ['' => ['declaredParameters' => [['username']], 'validators' => []]]]);
	}

	public function testApplyRejectsANonStringErrorMessage(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessageMatches('/Error message "min" .* must be a string/');
		$this->applyTo(['buckets' => ['' => ['validators' => [[
			'name' => 'broken',
			'class' => RegexValidator::class,
			'parameters' => ['name' => 'broken', 'pattern' => '/^x$/'],
			'errors' => ['min' => ['domains' => []]],
		]]]]]);
	}
}
