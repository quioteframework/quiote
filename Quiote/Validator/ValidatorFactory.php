<?php

namespace Quiote\Validator;

use Quiote\Context;
use Quiote\Exception\ConfigurationException;

/**
 * Builds a validator from its class name.
 *
 * The single construction point for validators named at runtime -- from `validators.xml`, from the
 * fluent builder's `raw()`/`group()`, and from {@see ValidationManager::createValidator()}. It
 * exists so those paths cannot drift apart, and so there is one place that decides *how* a
 * validator comes into being.
 *
 * Construction goes through the container's {@see \Quiote\DI\Container::make()}, which means a
 * validator may declare constructor dependencies like any other collaborator:
 *
 * ```php
 * final class VatNumberValidator extends Validator
 * {
 *     public function __construct(private readonly VatLookupService $lookup) {}
 * }
 * ```
 *
 * That is additive. A validator with no constructor -- which is every validator the framework
 * ships and every one written before this existed -- is `new`'d directly by `make()`, so nothing
 * about the existing path changes.
 *
 * `make()` is deliberately the entry point rather than `get()`: a validator is a per-validation
 * object, never a shared service, and `make()` results are not container-cached. That is also what
 * lets a validator depend on the request or the user, which a cached service could not.
 *
 * Note that the configuration a validator is given -- parameters, argument names, error messages --
 * still arrives through {@see Validator::initialize()}. Those are per-declaration *data* read out
 * of a config file, not collaborators, so there is nothing for the container to resolve them from.
 *
 * @since      4.0.0
 */
final class ValidatorFactory
{
    public function __construct(private readonly Context $context) {}

    /**
     * Build the validator named by $class, resolving any constructor dependencies it declares.
     *
     * Generic in $class, so a caller naming a concrete validator gets that type back rather than
     * having to narrow it again. The runtime check stays regardless: plenty of callers arrive from
     * a config file holding nothing better than a `class-string`.
     *
     * @template   T of object
     * @param      class-string<T> $class
     * @return     T&Validator
     * @throws     ConfigurationException When $class is not a {@see Validator}. Reported here
     *             rather than left to fail on the initialize() call above it, which would name the
     *             missing method instead of the actual mistake in the configuration.
     * @since      4.0.0
     */
    public function create(string $class): Validator
    {
        $validator = $this->context->getContainer()->make($class);

        if (!$validator instanceof Validator) {
            throw new ConfigurationException(sprintf(
                'Validator class "%s" does not extend %s.',
                $class,
                Validator::class,
            ));
        }

        return $validator;
    }
}
