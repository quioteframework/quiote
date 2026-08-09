<?php
namespace Quiote\Validator\Compiler\Runtime;

use Quiote\Validator\Validator;

/**
 * A fluent handle onto a single, already-registered Validator instance.
 * Every setter here is a thin wrapper over Validator::setParameter() --
 * safe to call any time before ValidationManager::execute() actually
 * validates, since parameters are read lazily by validate(). There is no
 * separate "build"/"commit" step: ValidatorBuilder addChild()s the
 * validator immediately when a spec is created, so a caller who never
 * chains anything still gets a correctly registered (if minimally
 * configured) validator.
 * @since      1.0.0
 */
final class ValidatorSpec
{
	public function __construct(private readonly Validator $validator)
	{
	}

	/** Returns the live Validator instance, for anything the setters below do not cover. */
	public function validator(): Validator
	{
		return $this->validator;
	}

	/** Sets the `required` parameter, deciding whether a missing argument is itself an error. */
	public function required(bool $required = true): self
	{
		$this->validator->setParameter('required', $required);
		return $this;
	}

	/** Sets the `severity` parameter, which decides how far a failure of this validator escalates the request's overall validation result. */
	public function severity(string $severity): self
	{
		$this->validator->setParameter('severity', $severity);
		return $this;
	}

	/** Sets the `export` parameter, naming the request-data key the validated value is written back to. */
	public function export(string $to): self
	{
		$this->validator->setParameter('export', $to);
		return $this;
	}

	/** Sets the `translation_domain` parameter used when this validator's error messages are translated. */
	public function translationDomain(string $domain): self
	{
		$this->validator->setParameter('translation_domain', $domain);
		return $this;
	}

	/**
	 * Sets an error message on the validator.
	 *
	 * `$for` names the specific failure the message belongs to; omitting it
	 * (or passing null) registers the message under the empty key, which is
	 * the validator's default message for any failure without its own text.
	 */
	public function error(string $message, ?string $for = null): self
	{
		$this->validator->setErrorMessage($for ?? '', $message);
		return $this;
	}

	// -- StringValidator --------------------------------------------------

	/** Sets the `min` parameter, the shortest accepted string length. */
	public function minLength(int $min): self
	{
		$this->validator->setParameter('min', $min);
		return $this;
	}

	/** Sets the `max` parameter, the longest accepted string length. */
	public function maxLength(int $max): self
	{
		$this->validator->setParameter('max', $max);
		return $this;
	}

	/** Sets the `trim` parameter, deciding whether surrounding whitespace is stripped before the value is checked. */
	public function trim(bool $trim = true): self
	{
		$this->validator->setParameter('trim', $trim);
		return $this;
	}

	/** Sets the `utf8` parameter, deciding whether lengths are counted in UTF-8 characters rather than bytes. */
	public function utf8(bool $utf8 = true): self
	{
		$this->validator->setParameter('utf8', $utf8);
		return $this;
	}

	// -- InarrayValidator ---------------------------------------------------

	/** Sets the `case` parameter, deciding whether the allowlist comparison respects letter case. */
	public function caseSensitive(bool $caseSensitive = true): self
	{
		$this->validator->setParameter('case', $caseSensitive);
		return $this;
	}

	/** Sets the `strict` parameter, deciding whether the allowlist comparison also requires a matching type. */
	public function strict(bool $strict = true): self
	{
		$this->validator->setParameter('strict', $strict);
		return $this;
	}

	// -- NumberValidator ----------------------------------------------------

	/** Sets the `min` parameter, the smallest accepted numeric value. */
	public function min(int|float $min): self
	{
		$this->validator->setParameter('min', $min);
		return $this;
	}

	/** Sets the `max` parameter, the largest accepted numeric value. */
	public function max(int|float $max): self
	{
		$this->validator->setParameter('max', $max);
		return $this;
	}

	/** Sets the `type` parameter, the numeric type the value must conform to. */
	public function type(string $type): self
	{
		$this->validator->setParameter('type', $type);
		return $this;
	}

	/** Sets the `cast_to` parameter, the type the accepted value is converted to before it is exported. */
	public function castTo(string $type): self
	{
		$this->validator->setParameter('cast_to', $type);
		return $this;
	}

	// -- RegexValidator -------------------------------------------------------

	/** Sets the `match` parameter; false inverts the regex test, so the value passes only when the pattern does *not* match. */
	public function shouldMatch(bool $shouldMatch = true): self
	{
		$this->validator->setParameter('match', $shouldMatch);
		return $this;
	}

	// -- AndoperatorValidator / OroperatorValidator --------------------------

	/** Sets the `break` parameter, deciding whether the operator stops running children once one has settled the outcome. */
	public function breakOnFirst(bool $break = true): self
	{
		$this->validator->setParameter('break', $break);
		return $this;
	}

	/** Sets the `skip_errors` parameter, deciding whether the errors reported by child validators are discarded in favour of the operator's own message. */
	public function skipErrors(bool $skip = true): self
	{
		$this->validator->setParameter('skip_errors', $skip);
		return $this;
	}
}
