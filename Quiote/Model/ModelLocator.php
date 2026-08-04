<?php

namespace Quiote\Model;

use Quiote\Context;
use Quiote\Exception\DisabledModuleException;
use Quiote\Exception\QuioteException;
use Quiote\Logging\Log;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Hands out model instances.
 *
 * Where {@see ModelClassResolver} answers "which class is this", the locator owns everything
 * that follows: bootstrapping the owning module, deciding whether an instance is shared,
 * constructing it, and running the initialize() hand-off. Those are lifetime concerns, and they
 * change for different reasons than the naming conventions do -- which is why they are not the
 * same class.
 *
 * Singleton models are cached per context, and that cache is request-scoped state: it is
 * dropped by {@see reset()} at the worker request boundary, because a model holding request
 * data would otherwise serve it to the next request.
 *
 * @since      4.0.0
 */
final class ModelLocator implements ResetInterface
{
    /**
     * @var        array<class-string, Model> Shared instances of {@see ISingletonModel}
     *             implementations, one per class per context.
     */
    private array $singletons = [];

    public function __construct(
        private readonly Context $context,
        private readonly ModelClassResolver $resolver,
    ) {}

    /**
     * Retrieve a model instance.
     *
     * @param      string $modelName A model name or fully qualified class name.
     * @param      ?string $moduleName A module name for a module model, null for a global one.
     * @param      ?array<int, mixed> $parameters Passed to the constructor (when the class
     *             declares one) and to initialize().
     * @throws     QuioteException When no class exists for the name, or the class that does is
     *             not a {@see Model}.
     * @since      4.0.0
     */
    public function get(
        string $modelName,
        ?string $moduleName = null,
        ?array $parameters = null,
    ): Model {
        // Module bootstrapping has per-request side effects (autoload, config) and runs on
        // every call regardless of whether the resolution below is a cache hit.
        if ($moduleName !== null) {
            $this->initializeModule($moduleName);
        }

        $resolved = $this->resolver->resolve($modelName, $moduleName);

        $model = $resolved->isSingleton
            ? $this->singletons[$resolved->class] ??= $this->instantiate($resolved, $parameters)
            : $this->instantiate($resolved, $parameters);

        // Re-run for a cached singleton too: initialize() is where a model picks up this
        // request's context and the caller's parameters.
        $model->initialize($this->context, (array) $parameters);

        return $model;
    }

    /**
     * Drop the shared singleton instances at the worker request boundary. The resolution cache
     * is deliberately kept -- it holds class names, not request state.
     *
     * @since      4.0.0
     */
    public function reset(): void
    {
        $this->singletons = [];
    }

    /**
     * Load the module's autoload and configuration before its models are resolved.
     *
     * A disabled module still gets its autoload loaded, which is the whole reason for the call:
     * resolving a model out of a disabled module stays legal, so the typed rejection is
     * expected rather than exceptional here.
     *
     * @since      4.0.0
     */
    private function initializeModule(string $moduleName): void
    {
        try {
            $this->context->getController()->initializeModule($moduleName);
        } catch (DisabledModuleException $e) {
            Log::for($this)->debug(
                '[ModelLocator] module "' . $moduleName . '" is disabled; its autoload is '
                . 'loaded and model resolution continues: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Construct the resolved class. Parameters reach the constructor only when there is one to
     * take them; otherwise initialize() is the sole recipient.
     *
     * @param      ?array<int, mixed> $parameters
     * @throws     QuioteException When the resolved class is not a {@see Model}.
     * @since      4.0.0
     */
    private function instantiate(ResolvedModel $resolved, ?array $parameters): Model
    {
        $class = $resolved->class;
        $model = $parameters === null || !$resolved->hasConstructor
            ? new $class()
            : new $class(...$parameters);

        if (!$model instanceof Model) {
            throw new QuioteException(sprintf(
                'Resolved class for Model %s does not extend %s',
                $class,
                Model::class,
            ));
        }

        return $model;
    }
}
