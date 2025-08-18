<?php
declare(strict_types=1);

use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

/**
 * Converts a simplified subset of legacy Agavi test route arrays (produced by
 * serialized config cache) into Symfony RouteCollection + meta array expected
 * by AgaviRouting.
 * Supported legacy keys: name, pattern, module, action, defaults (assoc), routes (children)
 */
final class LegacyRouteTranslator { public static function translate(): void { throw new \RuntimeException('Legacy route translation removed'); } }
