<?php

namespace Quiote\Routing;

use Symfony\Component\Routing\RouteCollection;

abstract class Routes {
    /**
     * @return array{0: RouteCollection, 1: array<string, mixed>}
     */
    abstract public static function build(): array;
}