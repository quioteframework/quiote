<?php
namespace Quiote\Validator\Compiler\Runtime;

use InvalidArgumentException;
use Quiote\Context;
use Quiote\Validator\AndoperatorValidator;
use Quiote\Validator\BooleanValidator;
use Quiote\Validator\EmailValidator;
use Quiote\Validator\IValidatorContainer;
use Quiote\Validator\InarrayValidator;
use Quiote\Validator\IsNotEmptyValidator;
use Quiote\Validator\IssetValidator;
use Quiote\Validator\JsonValidator;
use Quiote\Validator\NotoperatorValidator;
use Quiote\Validator\NumberValidator;
use Quiote\Validator\OroperatorValidator;
use Quiote\Validator\RegexValidator;
use Quiote\Validator\StringValidator;
use Quiote\Validator\Validator;
use Quiote\Validator\XoroperatorValidator;

/**
 * Fluent facade for registering validators directly in PHP, without an
 * intervening XML file. This is the runtime counterpart to
 * FluentSourceEmitter's generated code: both target the exact same
 * addChild() call the XML path has always used
 * (ValidatorConfigHandler/RuntimeArrayEmitter), so a validator registered
 * this way gets the same strict-mode whitelist/pruning guarantee as one
 * declared in validators.xml -- see Action::registerValidators() and
 * CompiledValidatorRegistry.
 *
 * A misspelled call here (e.g. ->onArray() instead of ->oneOf()) is a
 * fatal "call to undefined method" at registration time, not a silently
 * ignored parameter -- which is the whole point: this is the fix for the
 * incident where `values="a,b,c"` was silently absorbed by a validator
 * that never read it.
 * @since      1.0.0
 */
final class ValidatorBuilder
{
	private const OPERATORS = [
		'and' => AndoperatorValidator::class,
		'or' => OroperatorValidator::class,
		'not' => NotoperatorValidator::class,
		'xor' => XoroperatorValidator::class,
	];

	public function __construct(
		private readonly IValidatorContainer $container,
		private readonly Context $context,
		private readonly ?string $method = null,
	) {
	}

	/**
	 * @param ?string $method The resolved action method token
	 *                            ('read'/'write'/...), i.e. the same value
	 *                            ValidationService passes as its own
	 *                            $method argument -- NOT the raw HTTP verb.
	 *                            Callers (CompiledValidatorRegistry) already
	 *                            have this from the validation call they're
	 *                            servicing.
	 */
	public static function on(IValidatorContainer $container, Context $context, ?string $method = null): self
	{
		return new self($container, $context, $method);
	}

	/**
	 * The resolved action method token this builder was constructed for
	 * ('read'/'write'/... or null), mirroring the `$method` variable
	 * available in compiled XML validator code, so hand-written registrars
	 * can branch the same way: `if ($v->method() === 'write') { ... }`.
	 */
	public function method(): ?string
	{
		return $this->method;
	}

	public function getContext(): Context
	{
		return $this->context;
	}

	public function string(string $argument, bool $required = true): ValidatorSpec
	{
		return $this->add(new StringValidator(), [$argument], ['required' => $required]);
	}

	/**
	 * @param mixed[] $values The allowlist. This is what the incident's
	 *                        `values="a,b,c"` attribute was meant to be --
	 *                        here it's a required, typed argument instead
	 *                        of an attribute a validator might silently
	 *                        ignore.
	 */
	public function enum(string $argument, array $values, bool $required = true): ValidatorSpec
	{
		return $this->add(new InarrayValidator(), [$argument], ['values' => $values, 'sep' => ',', 'required' => $required]);
	}

	public function email(string $argument, bool $required = true): ValidatorSpec
	{
		return $this->add(new EmailValidator(), [$argument], ['required' => $required]);
	}

	public function number(string $argument, bool $required = true): ValidatorSpec
	{
		return $this->add(new NumberValidator(), [$argument], ['required' => $required]);
	}

	public function boolean(string $argument, bool $required = true): ValidatorSpec
	{
		return $this->add(new BooleanValidator(), [$argument], ['required' => $required]);
	}

	public function regex(string $argument, string $pattern, bool $shouldMatch = true, bool $required = true): ValidatorSpec
	{
		return $this->add(new RegexValidator(), [$argument], ['pattern' => $pattern, 'match' => $shouldMatch, 'required' => $required]);
	}

	public function isNotEmpty(string $argument, bool $required = true): ValidatorSpec
	{
		return $this->add(new IsNotEmptyValidator(), [$argument], ['required' => $required]);
	}

	public function isSet(string $argument, bool $required = true): ValidatorSpec
	{
		return $this->add(new IssetValidator(), [$argument], ['required' => $required]);
	}

	public function json(string $argument, bool $required = true): ValidatorSpec
	{
		return $this->add(new JsonValidator(), [$argument], ['required' => $required]);
	}

	/**
	 * Registers an and/or/not/xor container and yields a nested builder
	 * scoped to it, so children addChild() onto the container instead of
	 * the outer validation manager.
	 * @param string $operator One of 'and', 'or', 'not', 'xor' — not enforced by
	 *                the native `string` param type, so callers can pass an
	 *                invalid value at runtime; the check below is load-bearing.
	 */
	public function group(string $operator, callable $configure): ValidatorSpec
	{
		$class = self::OPERATORS[$operator] ?? throw new InvalidArgumentException(
			'Unknown validator group operator "' . $operator . '"; expected one of: ' . implode(', ', array_keys(self::OPERATORS))
		);

		$validator = new $class();
		$validator->initialize($this->context, [], [], []);
		$this->container->addChild($validator);

		$configure(new self($validator, $this->context, $this->method));

		return new ValidatorSpec($validator);
	}

	/**
	 * Escape hatch for any validator class without a dedicated fluent
	 * method above (custom app validators, or framework validators this
	 * builder hasn't grown a helper for yet -- see FluentSourceEmitter's
	 * UNMAPPABLE_PARAMETER passthrough). Always behaviorally complete:
	 * arguments/parameters/errors are passed through untouched, so this
	 * never loses information the way an incomplete fluent mapping could.
	 * @param class-string<Validator> $class
	 * @param array<int|string, mixed> $arguments
	 * @param array<string, mixed> $parameters
	 * @param array<string, string> $errors
	 * @param callable(self): void|null $children If given, and the
	 *        created validator implements IValidatorContainer, invoked
	 *        with a nested builder scoped to it -- for operator-like
	 *        validators (including ones with parameters/base paths that
	 *        don't fit group()'s generic assumptions) that still need
	 *        children attached.
	 */
	public function raw(string $class, array $arguments, array $parameters = [], array $errors = [], ?callable $children = null): ValidatorSpec
	{
		$spec = $this->add(new $class(), $arguments, $parameters, $errors);

		if ($children !== null) {
			$validator = $spec->validator();
			if (!$validator instanceof IValidatorContainer) {
				throw new InvalidArgumentException($class . ' does not implement IValidatorContainer; it cannot have children.');
			}
			$children(new self($validator, $this->context, $this->method));
		}

		return $spec;
	}

	/**
	 * @param array<int|string, mixed> $arguments
	 * @param array<string, mixed> $parameters
	 * @param array<string, string> $errors
	 */
	private function add(Validator $validator, array $arguments, array $parameters, array $errors = []): ValidatorSpec
	{
		$validator->initialize($this->context, $parameters, $arguments, $errors);
		$this->container->addChild($validator);
		return new ValidatorSpec($validator);
	}
}
