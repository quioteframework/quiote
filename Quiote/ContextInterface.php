<?php

namespace Quiote;

/**
 * What a collaborator needs from the application context: the accessors that reach the
 * framework's other pieces.
 *
 * Deliberately narrower than {@see Context} itself. The context's lifecycle -- initialize(),
 * shutdown(), reset(), handle(), the factory-info registry, the static instance registry --
 * belongs to whoever owns the context, not to the services, actions and views handed one.
 * Those consumers only read from it, so this is the surface they should depend on, and the
 * surface a test double has to satisfy.
 *
 * Type-hint this in new code. {@see Context} implements it, and the container resolves it to
 * the request's context, so `__construct(private ContextInterface $context)` works.
 *
 * @since      3.2.0
 */
interface ContextInterface
{
    /**
     * The name of this context profile.
     * @return     string
     */
    public function getName();

    /**
     * The current request.
     * @return     \Quiote\Request\WebRequest
     */
    public function getRequest();

    /**
     * The current user.
     * @return     \Quiote\User\User|\Quiote\User\ISecurityUser
     */
    public function getUser();

    /**
     * The routing component.
     * @return     \Quiote\Routing\Routing
     */
    public function getRouting();

    /**
     * The controller dispatching this request.
     * @return     \Quiote\Controller\Controller
     */
    public function getController();

    /**
     * This request's session bag. Never null: a context with no session backend answers a
     * bag whose reads return defaults and whose writes are discarded.
     */
    public function getSessionBag(): \Quiote\Session\SessionBagInterface;

    /**
     * The translation manager, or null when translation is disabled.
     * @return     ?\Quiote\Translation\TranslationManager
     */
    public function getTranslationManager();

    /**
     * The database manager, or null when database support is disabled.
     * @return     ?\Quiote\Database\DatabaseManager
     */
    public function getDatabaseManager();

    /**
     * A named database connection, or null when database support is disabled.
     * @param      ?string $name A database name.
     * @return     mixed
     */
    public function getDatabaseConnection($name = null);

    /**
     * A model instance, resolved by name and optional module.
     * @param      string $modelName A model name or fully qualified class name.
     * @param      ?string $moduleName A module name for a module model, null for a global one.
     * @param      ?array<int, mixed> $parameters Passed to the constructor and initialize().
     * @return     \Quiote\Model\Model
     */
    public function getModel($modelName, $moduleName = null, ?array $parameters = null);

    /**
     * The DI container backing this context.
     */
    public function getContainer(): \Quiote\DI\Container;

    /**
     * Resolve a service from the container. Prefer constructor injection; this is for
     * lazy or conditional access.
     */
    public function getService(string $id): mixed;

    /**
     * This request's correlation id, or null outside a handled request.
     */
    public function getCorrelationId(): ?string;

    /**
     * The asset registry shared by this request's whole render tree.
     */
    public function getAssetRegistry(): \Quiote\Asset\AssetRegistry;

    /**
     * The dispatcher for sub-action (slot) execution.
     */
    public function getSlotDispatcher(): \Quiote\Execution\SlotDispatcher;
}
