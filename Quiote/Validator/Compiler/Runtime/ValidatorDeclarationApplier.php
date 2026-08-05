<?php

declare(strict_types=1);

namespace Quiote\Validator\Compiler\Runtime;

use Quiote\Context;
use Quiote\Exception\ConfigurationException;
use Quiote\Validator\IValidatorContainer;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

/**
 * Builds the validators a compiled validator config declares and attaches them to a validation
 * manager.
 *
 * This is the code that used to be the compiled artifact itself: `new X(); ->initialize(...);
 * ->addChild(...)`, emitted as text and executed in the caller's scope. It is here instead, so the
 * artifact is data -- a poisoned config cache entry can then only describe wrong validators, not run
 * code -- and so registration can be tested directly rather than by executing a generated string.
 *
 * @see \Quiote\Validator\Compiler\RuntimeDeclarationEmitter for the declaration's shape.
 * @since      4.0.0
 */
final class ValidatorDeclarationApplier
{
    /**
     * Register the declared validators for one request method.
     *
     * The methodless bucket is applied first and then the bucket matching `$method`, which is the
     * order the declaration lists them in: a parameter declared for every method is whitelisted
     * whether or not a method bucket matches.
     *
     * @param      mixed $declaration The value the compiled validator config returned.
     * @param      ValidationManager $validationManager The manager to register against.
     * @param      string $method The request method token the declaration is matched against
     *                    (lowercase, as the compiler wrote it).
     * @param      Context $context The context validators are initialized with.
     * @param      string $sourceRef The validator config file, for diagnostics.
     * @return     void
     * @throws     ConfigurationException If the declaration is not the shape the compiler produces.
     * @since      4.0.0
     */
    public static function apply(
        mixed $declaration,
        ValidationManager $validationManager,
        string $method,
        Context $context,
        string $sourceRef,
    ): void {
        if (!is_array($declaration) || !isset($declaration['buckets']) || !is_array($declaration['buckets'])) {
            throw new ConfigurationException(sprintf(
                'The compiled validator declaration from "%s" must be an array with a "buckets" key, got %s.',
                $sourceRef,
                get_debug_type($declaration)
            ));
        }

        /** @var array<array-key, mixed> $buckets */
        $buckets = $declaration['buckets'];

        // A blank method token would apply the unconditional bucket twice, and
        // ValidationManager::addChild() rejects a duplicate name.
        $bucketKeys = $method === '' ? [''] : ['', $method];

        // Built validators are keyed by their own name, which is what a child names as its parent.
        $built = [];
        foreach ($bucketKeys as $bucketKey) {
            if (!isset($buckets[$bucketKey])) {
                continue;
            }

            self::applyBucket($buckets[$bucketKey], $bucketKey, $validationManager, $context, $sourceRef, $built);
        }
    }

    /**
     * @param      array<string, Validator> $built Validators created so far, by name, so a child can
     *                    find the container it attaches to.
     * @since      4.0.0
     */
    private static function applyBucket(
        mixed $bucket,
        string $bucketKey,
        ValidationManager $validationManager,
        Context $context,
        string $sourceRef,
        array &$built,
    ): void {
        if (!is_array($bucket)) {
            throw new ConfigurationException(sprintf(
                'Bucket "%s" of the compiled validator declaration from "%s" must be an array, got %s.',
                $bucketKey,
                $sourceRef,
                get_debug_type($bucket)
            ));
        }

        $declaredParameters = $bucket['declaredParameters'] ?? [];
        if (!is_array($declaredParameters)) {
            throw new ConfigurationException(sprintf(
                'The "declaredParameters" of bucket "%s" in the compiled validator declaration from "%s" must be a list, got %s.',
                $bucketKey,
                $sourceRef,
                get_debug_type($declaredParameters)
            ));
        }
        if ($declaredParameters !== []) {
            self::declareParameters($validationManager, array_values(array_map(
                static fn(mixed $name): string => is_string($name) ? $name : throw new ConfigurationException(sprintf(
                    'A declared parameter name in bucket "%s" of the compiled validator declaration from "%s" must be a string, got %s.',
                    $bucketKey,
                    $sourceRef,
                    get_debug_type($name)
                )),
                $declaredParameters
            )));
        }

        $validators = $bucket['validators'] ?? [];
        if (!is_array($validators)) {
            throw new ConfigurationException(sprintf(
                'The "validators" of bucket "%s" in the compiled validator declaration from "%s" must be a list, got %s.',
                $bucketKey,
                $sourceRef,
                get_debug_type($validators)
            ));
        }

        foreach ($validators as $spec) {
            if (!is_array($spec)) {
                throw new ConfigurationException(sprintf(
                    'A validator entry in bucket "%s" of the compiled validator declaration from "%s" must be an array, got %s.',
                    $bucketKey,
                    $sourceRef,
                    get_debug_type($spec)
                ));
            }

            $validator = self::build($spec, $bucketKey, $context, $sourceRef);
            $parent = self::resolveParent($spec, $validationManager, $built, $bucketKey, $sourceRef);
            $parent->addChild($validator);

            $name = $validator->getName();
            if (is_string($name)) {
                $built[$name] = $validator;
            }
        }
    }

    /**
     * Whitelist request parameter names for strict-mode validation.
     *
     * The request is immutable, so declaring a name produces a new instance the context has to be
     * given back -- which is why this goes through the manager's own context rather than the one
     * validators are initialized with.
     *
     * @param      list<string> $names
     * @since      4.0.0
     */
    private static function declareParameters(ValidationManager $validationManager, array $names): void
    {
        $context = $validationManager->getContext();
        $context->setRequest($context->getRequest()->declareParameters($names));
    }

    /**
     * @param      array<array-key, mixed> $spec
     * @since      4.0.0
     */
    private static function build(array $spec, string $bucketKey, Context $context, string $sourceRef): Validator
    {
        $class = $spec['class'] ?? null;
        if (!is_string($class) || !class_exists($class) || !is_a($class, Validator::class, true)) {
            throw new ConfigurationException(sprintf(
                'A validator entry in bucket "%s" of the compiled validator declaration from "%s" names "%s", which is not a %s.',
                $bucketKey,
                $sourceRef,
                is_string($class) ? $class : get_debug_type($class),
                Validator::class
            ));
        }

        $validator = new $class();
        $validator->initialize(
            $context,
            self::stringKeyed(self::arrayField($spec, 'parameters', $bucketKey, $sourceRef)),
            self::arrayField($spec, 'arguments', $bucketKey, $sourceRef),
            self::errorMessages(self::arrayField($spec, 'errors', $bucketKey, $sourceRef), $bucketKey, $sourceRef)
        );

        return $validator;
    }

    /**
     * @param      array<array-key, mixed> $spec
     * @return     array<array-key, mixed>
     * @since      4.0.0
     */
    private static function arrayField(array $spec, string $field, string $bucketKey, string $sourceRef): array
    {
        $value = $spec[$field] ?? [];
        if (!is_array($value)) {
            throw new ConfigurationException(sprintf(
                'The "%s" of a validator entry in bucket "%s" of the compiled validator declaration from "%s" must be an array, got %s.',
                $field,
                $bucketKey,
                $sourceRef,
                get_debug_type($value)
            ));
        }

        return $value;
    }

    /**
     * Re-key a declared bag for a parameter typed `array<string, mixed>`. A value read back from a
     * cache carries no key-type guarantee, and `var_export()` of a numeric-looking name gives an int
     * key back.
     *
     * @param      array<array-key, mixed> $values
     * @return     array<string, mixed>
     * @since      4.0.0
     */
    private static function stringKeyed(array $values): array
    {
        $keyed = [];
        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * The declared error-message overrides, keyed by error name (`''` being the validator's default
     * message).
     *
     * The validators schema gives an `<error>` a `for` attribute and text, so every override is a
     * string; anything else means the declaration was not produced by the compiler.
     *
     * @param      array<array-key, mixed> $errors
     * @return     array<string, string>
     * @since      4.0.0
     */
    private static function errorMessages(array $errors, string $bucketKey, string $sourceRef): array
    {
        $messages = [];
        foreach ($errors as $name => $message) {
            if (!is_string($message)) {
                throw new ConfigurationException(sprintf(
                    'Error message "%s" of a validator entry in bucket "%s" of the compiled validator declaration '
                    . 'from "%s" must be a string, got %s.',
                    (string) $name,
                    $bucketKey,
                    $sourceRef,
                    get_debug_type($message)
                ));
            }
            $messages[(string) $name] = $message;
        }

        return $messages;
    }

    /**
     * The container a declared validator attaches to: the validation manager, or the named validator
     * built earlier in this same application.
     *
     * @param      array<array-key, mixed> $spec
     * @param      array<string, Validator> $built
     * @since      4.0.0
     */
    private static function resolveParent(
        array $spec,
        ValidationManager $validationManager,
        array $built,
        string $bucketKey,
        string $sourceRef,
    ): IValidatorContainer {
        $parent = $spec['parent'] ?? null;
        if ($parent === null) {
            return $validationManager;
        }

        if (!is_string($parent) || !isset($built[$parent])) {
            throw new ConfigurationException(sprintf(
                'A validator entry in bucket "%s" of the compiled validator declaration from "%s" attaches to "%s", '
                . 'which is not a validator declared before it.',
                $bucketKey,
                $sourceRef,
                is_string($parent) ? $parent : get_debug_type($parent)
            ));
        }

        $container = $built[$parent];
        if (!$container instanceof IValidatorContainer) {
            throw new ConfigurationException(sprintf(
                'A validator entry in bucket "%s" of the compiled validator declaration from "%s" attaches to "%s", '
                . 'which is a %s and cannot hold children.',
                $bucketKey,
                $sourceRef,
                $parent,
                $container::class
            ));
        }

        return $container;
    }
}
