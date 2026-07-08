<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Validator\Compiler\Runtime\ValidatorBuilder;
use Quiote\Validator\IValidatorContainer;
use Quiote\Validator\Validator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\InarrayValidator;
use Quiote\Validator\StringValidator;

class ValidatorBuilderTest extends UnitTestCase
{
	private function newManager(): ValidationManager
	{
		return $this->getContext()->createInstanceFor('validation_manager');
	}

	public function testStringRegistersImmediatelyWithChainedParameters(): void
	{
		$vm = $this->newManager();
		$v = ValidatorBuilder::on($vm, $this->getContext());

		$spec = $v->string('username')->minLength(3)->maxLength(32)->trim();

		$this->assertSame($vm->getChilds(), array_filter($vm->getChilds(), fn($c) => $c === $spec->validator()));
		$this->assertInstanceOf(StringValidator::class, $spec->validator());
		$this->assertSame(3, $spec->validator()->getParameter('min'));
		$this->assertSame(32, $spec->validator()->getParameter('max'));
		$this->assertTrue($spec->validator()->getParameter('trim'));
		$this->assertTrue($spec->validator()->getParameter('required'));
	}

	public function testEnumEnforcesAllowlistEndToEnd(): void
	{
		// This is the exact guarantee the incident needed: a real, enforced
		// allowlist reachable via a typed, autocompletable method -- not a
		// string attribute a validator might silently ignore.
		$vm = $this->newManager();
		$v = ValidatorBuilder::on($vm, $this->getContext());
		$v->enum('status', ['pending', 'approved', 'rejected']);

		$request = $this->newWebRequest(['status' => 'pending']);
		$this->assertTrue($vm->execute($request));

		$vm2 = $this->newManager();
		$v2 = ValidatorBuilder::on($vm2, $this->getContext());
		$v2->enum('status', ['pending', 'approved', 'rejected']);
		$injected = $this->newWebRequest(['status' => "'; DROP TABLE users; --"]);
		$this->assertFalse($vm2->execute($injected));
	}

	public function testRequiredFalseAllowsMissingArgument(): void
	{
		$vm = $this->newManager();
		$v = ValidatorBuilder::on($vm, $this->getContext());
		$v->email('contact', false);

		$request = $this->newWebRequest([]);
		$this->assertTrue($vm->execute($request));
	}

	public function testGroupNestsValidatorsUnderOperatorContainer(): void
	{
		$vm = $this->newManager();
		$v = ValidatorBuilder::on($vm, $this->getContext());

		$group = $v->group('or', function (ValidatorBuilder $inner) {
			$inner->string('a', false);
			$inner->string('b', false);
		});

		$this->assertCount(1, $vm->getChilds());
		$groupValidator = $group->validator();
		$this->assertInstanceOf(IValidatorContainer::class, $groupValidator);
		$this->assertCount(2, $groupValidator->getChilds());
	}

	public function testGroupRejectsUnknownOperator(): void
	{
		$vm = $this->newManager();
		$v = ValidatorBuilder::on($vm, $this->getContext());

		$this->expectException(InvalidArgumentException::class);
		$v->group('nand', function () {});
	}

	public function testRawEscapeHatchInstantiatesGivenClass(): void
	{
		$vm = $this->newManager();
		$v = ValidatorBuilder::on($vm, $this->getContext());

		$spec = $v->raw(InarrayValidator::class, ['status'], ['values' => ['a', 'b'], 'sep' => ',']);
		$this->assertInstanceOf(InarrayValidator::class, $spec->validator());
		$this->assertSame(['a', 'b'], $spec->validator()->getParameter('values'));
	}

	public function testMethodReturnsConstructorProvidedToken(): void
	{
		$vm = $this->newManager();
		$v = ValidatorBuilder::on($vm, $this->getContext(), 'write');
		$this->assertSame('write', $v->method());

		$v2 = ValidatorBuilder::on($vm, $this->getContext());
		$this->assertNull($v2->method());
	}
}
?>
