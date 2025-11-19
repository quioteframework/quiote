<?php

namespace Agavi\Routing;

abstract class Routes {
    abstract public static function build(): array;
}