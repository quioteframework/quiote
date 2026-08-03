<?php

namespace Quiote\Validator;

use Quiote\Context;
use Quiote\Request\WebRequest;

/**
 * What a validator container asks of a validator: configure it, run it against a request, and
 * read back what it named, decided and exported.
 *
 * The {@see Validator} base class supplies all of it, along with ~40 protected helpers for
 * writing one. This interface is the public contract a container and a test double need, so
 * nothing has to depend on that base class to hold or drive a validator.
 *
 * @since      3.2.0
 */
interface ValidatorInterface
{
    /**
     * Configure this validator from its declaration.
     *
     * @param      array<string, mixed> $parameters Validator parameters.
     * @param      array<int|string, mixed> $arguments Argument names this validator targets.
     * @param      array<int|string, mixed> $errors Error messages by index.
     * @return     mixed
     */
    public function initialize(Context $context, array $parameters = [], array $arguments = [], array $errors = []);

    /**
     * Run this validator against $parameters.
     *
     * @return     int The validation result code.
     */
    public function execute(WebRequest $parameters);

    /**
     * This validator's declared name.
     * @return     ?string
     */
    public function getName();

    /**
     * The base path relative argument names resolve against.
     * @return     mixed
     */
    public function getBase();

    /**
     * The argument names this validator targets.
     * @return     array<int|string, mixed>
     */
    public function getArguments();

    /**
     * The container holding this validator.
     * @return     ?IValidatorContainer
     */
    public function getParentContainer();

    /**
     * Attach this validator to its container.
     * @return     mixed
     */
    public function setParentContainer(IValidatorContainer $parent);

    /**
     * The dependency manager coordinating provides/depends between validators.
     * @return     ?DependencyManager
     */
    public function getDependencyManager();

    /**
     * Override the message reported for one error index.
     */
    public function setErrorMessage(string $index, string $message): void;

    /**
     * The request this validator produced, when it exported values, or null when it changed
     * nothing. Validators run against an immutable request, so an export is a new instance
     * the caller has to pick up.
     */
    public function getMutatedRequest(): ?WebRequest;

    /**
     * Release anything held for the duration of one validation run.
     * @return     mixed
     */
    public function shutdown();
}
