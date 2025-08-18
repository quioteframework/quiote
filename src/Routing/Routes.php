<?php

namespace Jakamo\Routing;

abstract class Routes {
    abstract public static function build(): array;
}