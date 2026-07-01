<?php

namespace Quiote\Routing;

abstract class Routes {
    abstract public static function build(): array;
}