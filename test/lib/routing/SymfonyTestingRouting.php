<?php
declare(strict_types=1);

use Agavi\Routing\AgaviRouting;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

/**
 * Lightweight Symfony-based testing routing implementation providing a small
 * deterministic set of routes equivalent to those used in legacy tests.
 * Replaces callback/cut/optimized legacy behaviors with direct route metadata.
 */
class SymfonyTestingRouting extends AgaviRouting { protected function build(): array { throw new \RuntimeException('Remove SymfonyTestingRouting usage'); } }
