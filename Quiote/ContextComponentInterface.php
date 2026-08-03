<?php

namespace Quiote;

/**
 * A core component the {@see Context} constructs from the factory metadata captured at
 * {@see Context::initialize()} and drives through a two-step lifecycle.
 *
 * The request, user, routing and database manager are all built this way, and all four are
 * rebuilt on demand after the worker request boundary nulls them. This interface is what
 * lets {@see Context} express that rebuild once instead of per component.
 *
 * Return types are deliberately left undeclared: implementations are free to declare
 * `void` (or anything else), which keeps application subclasses of the shipped components
 * compatible whether or not they declare one.
 *
 * @since      3.2.0
 */
interface ContextComponentInterface
{
    /**
     * Configure this component against the context that owns it.
     *
     * Always called before {@see startup()}, with the parameters recorded alongside the
     * component's class name in its factory metadata.
     *
     * @param      array<string, mixed> $parameters
     * @return     mixed Ignored; declared untyped so implementations may narrow it.
     */
    public function initialize(Context $context, array $parameters = []);

    /**
     * Begin this component's active life, after {@see initialize()} has configured it.
     *
     * @return     mixed Ignored; declared untyped so implementations may narrow it.
     */
    public function startup();
}
