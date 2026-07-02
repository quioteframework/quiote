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

	/** Escape hatch: the live Validator instance, for anything not covered below. */
	public function validator(): Validator
	{
		return $this->validator;
	}

	public function required(bool $required = true): self
	{
		$this->validator->setParameter('required', $required);
		return $this;
	}

	public function severity(string $severity): self
	{
		$this->validator->setParameter('severity', $severity);
		return $this;
	}

	public function export(string $to): self
	{
		$this->validator->setParameter('export', $to);
		return $this;
	}

	public function translationDomain(string $domain): self
	{
		$this->validator->setParameter('translation_domain', $domain);
		return $this;
	}

	public function error(string $message, ?string $for = null): self
	{
		$this->validator->setErrorMessage($for ?? '', $message);
		return $this;
	}

	// -- StringValidator --------------------------------------------------

	public function minLength(int $min): self
	{
		$this->validator->setParameter('min', $min);
		return $this;
	}

	public function maxLength(int $max): self
	{
		$this->validator->setParameter('max', $max);
		return $this;
	}

	public function trim(bool $trim = true): self
	{
		$this->validator->setParameter('trim', $trim);
		return $this;
	}

	public function utf8(bool $utf8 = true): self
	{
		$this->validator->setParameter('utf8', $utf8);
		return $this;
	}

	// -- InarrayValidator ---------------------------------------------------

	public function caseSensitive(bool $caseSensitive = true): self
	{
		$this->validator->setParameter('case', $caseSensitive);
		return $this;
	}

	public function strict(bool $strict = true): self
	{
		$this->validator->setParameter('strict', $strict);
		return $this;
	}

	// -- NumberValidator ----------------------------------------------------

	public function min(int|float $min): self
	{
		$this->validator->setParameter('min', $min);
		return $this;
	}

	public function max(int|float $max): self
	{
		$this->validator->setParameter('max', $max);
		return $this;
	}

	public function type(string $type): self
	{
		$this->validator->setParameter('type', $type);
		return $this;
	}

	public function castTo(string $type): self
	{
		$this->validator->setParameter('cast_to', $type);
		return $this;
	}

	// -- RegexValidator -------------------------------------------------------

	public function shouldMatch(bool $shouldMatch = true): self
	{
		$this->validator->setParameter('match', $shouldMatch);
		return $this;
	}

	// -- AndoperatorValidator / OroperatorValidator --------------------------

	public function breakOnFirst(bool $break = true): self
	{
		$this->validator->setParameter('break', $break);
		return $this;
	}

	public function skipErrors(bool $skip = true): self
	{
		$this->validator->setParameter('skip_errors', $skip);
		return $this;
	}
}
