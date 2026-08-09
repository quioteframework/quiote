<?php

namespace Quiote\Routing;

use Symfony\Component\Routing\RouteCollection;

/**
 * Contract for a class that supplies a complete route table.
 *
 * {@see build()} returns the same tuple a {@see Routing} subclass produces from
 * its own build(): a Symfony {@see RouteCollection} paired with the meta array
 * keyed by route name that carries the Quiote-specific per-route data. Route
 * aggregates generated from an application's `routing.xml` expose exactly this
 * static entry point, so a Routing subclass can call `Routes::build()` and
 * either return the tuple unchanged or merge further routes into it first --
 * `#[Route]`-attributed ones, for instance.
 *
 * Being static, it needs no framework state: the route table is a declaration,
 * independent of the Context it will be installed into.
 */
abstract class Routes {
    /**
     * @return array{0: RouteCollection, 1: array<string, mixed>}
     */
    abstract public static function build(): array;
}