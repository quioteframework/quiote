<?php
declare(strict_types=1);

use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

/**
 * Converts a simplified subset of legacy Quiote test route arrays (produced by
 * serialized config cache) into Symfony RouteCollection + meta array expected
 * by Routing.
 * Supported legacy keys: name, pattern, module, action, defaults (assoc), routes (children)
 */
final class LegacyRouteTranslator { public static function translate(): never { throw new \RuntimeException('Legacy route translation removed'); } }
