<?php

namespace Quiote;

/**
 * What a collaborator needs from the application context: which profile this execution belongs to,
 * and a way to reach its services.
 *
 * Two methods, where 3.2 declared seventeen. The accessors are gone deliberately: a class that needs
 * the routing, the user or a service should say so in its constructor and let the container hand it
 * over. Reaching them through the context works no matter what the class is, which is exactly the
 * property that made every collaborator's real dependencies invisible.
 *
 * The profile concept is genuinely useful -- web, console, a named profile -- and a container handle
 * is the deliberate way out for the cases that cannot be wired statically, so neither is a smell to
 * be removed. What is left is what the name actually means.
 *
 * Type-hint this in new code. {@see Context} implements it, and the container resolves it to the
 * request's context, so `__construct(private ContextInterface $context)` works.
 *
 * The lifecycle -- initialize(), shutdown(), reset(), the instance registry -- was never here and
 * still is not: it belongs to whoever owns the context, not to the services, actions and views handed
 * one.
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
     * The container this profile's services are resolved from.
     * @since      3.2.0
     */
    public function getContainer(): \Quiote\DI\Container;
}
