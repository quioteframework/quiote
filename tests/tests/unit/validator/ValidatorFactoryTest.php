<?php

use Quiote\Context;
use Quiote\Exception\ConfigurationException;
use Quiote\Request\RequestState;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Validator\StringValidator;
use Quiote\Validator\Validator;
use Quiote\Validator\ValidatorFactory;

/**
 * The point of routing validator construction through the container: a validator may declare
 * constructor dependencies. Every validator the framework ships declares none, so the
 * no-constructor path is the one that must not have changed.
 */
#[IsolationEnvironment('testing')]
class ValidatorFactoryTest extends PhpUnitTestCase
{
	private function factory(): ValidatorFactory
	{
		return new ValidatorFactory(Context::getInstance());
	}

	/**
	 * The untouched majority. A validator with no constructor is built exactly as `new` would.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testCreateBuildsAConstructorlessValidator(): void
	{
		$validator = $this->factory()->create(StringValidator::class);

		$this->assertInstanceOf(StringValidator::class, $validator);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testCreateReturnsAFreshInstanceEveryTime(): void
	{
		$factory = $this->factory();

		$this->assertNotSame(
			$factory->create(StringValidator::class),
			$factory->create(StringValidator::class),
			'a validator is a per-validation object, never a shared service',
		);
	}

	/**
	 * The new capability.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testCreateResolvesAValidatorsConstructorDependencies(): void
	{
		$validator = $this->factory()->create(DependencyDeclaringValidator::class);

		$this->assertInstanceOf(DependencyDeclaringValidator::class, $validator);
		$this->assertInstanceOf(RequestState::class, $validator->requestState);
	}

	/**
	 * A validator is built per validation and never cached, so it may depend on request-scoped
	 * collaborators -- which a container-cached service could not.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testAValidatorMayDependOnRequestScopedState(): void
	{
		$ctx = Context::getInstance();

		$validator = $this->factory()->create(RequestScopedDependencyValidator::class);

		$this->assertSame($ctx->getRequest(), $validator->request);
	}

	/**
	 * A misconfigured validators.xml supplies a class name that is real but is not a validator.
	 * Reported here rather than left to fail on the initialize() call above it, which would name a
	 * missing method instead of the actual mistake in the configuration.
	 *
	 * The class arrives as a plain `class-string`, which is all a config file can offer -- and is
	 * why the runtime check has to exist even though create() is generic.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function dataNonValidatorClasses(): array
	{
		return [
			'a plain object' => [\stdClass::class],
			'a framework class that is not a validator' => [\Quiote\Util\ParameterHolder::class],
		];
	}

	/** @param class-string $class */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataNonValidatorClasses')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testCreateRejectsAClassThatIsNotAValidator(string $class): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('does not extend');

		$this->factory()->create($class);
	}

	/**
	 * ValidationManager::createValidator() is the path validators.xml and hand-written registrars
	 * take, so the capability has to be reachable through it and not only through the factory.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testCreateValidatorResolvesDependenciesAndStillRegistersTheChild(): void
	{
		$ctx = Context::getInstance();
		$manager = $ctx->getContainer()->get(\Quiote\Validator\ValidationManager::class);

		$validator = $manager->createValidator(
			DependencyDeclaringValidator::class,
			['field'],
			[],
			['name' => 'injected', 'severity' => 'error'],
		);

		$this->assertInstanceOf(DependencyDeclaringValidator::class, $validator);
		$this->assertInstanceOf(RequestState::class, $validator->requestState);
		// initialize() still carries the configuration; only construction moved.
		$this->assertSame('injected', $validator->getName());
		$this->assertSame($manager, $validator->getParentContainer());
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function testCreateValidatorStillWorksForAConstructorlessValidator(): void
	{
		$manager = Context::getInstance()->getContainer()->get(\Quiote\Validator\ValidationManager::class);

		$validator = $manager->createValidator(
			StringValidator::class,
			['field'],
			[],
			['name' => 'plain'],
		);

		$this->assertInstanceOf(StringValidator::class, $validator);
		$this->assertSame('plain', $validator->getName());
	}
}

/** Declares an ordinary singleton-safe collaborator. */
class DependencyDeclaringValidator extends Validator
{
	public function __construct(public readonly RequestState $requestState)
	{
	}

	protected function validate(): bool
	{
		return true;
	}
}

/**
 * Declares a request-scoped collaborator directly, which is legal precisely because a validator is
 * never container-cached.
 */
class RequestScopedDependencyValidator extends Validator
{
	public function __construct(public readonly \Quiote\Request\WebRequest $request)
	{
	}

	protected function validate(): bool
	{
		return true;
	}
}
