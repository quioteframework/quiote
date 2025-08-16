<?php
namespace Agavi\Middleware\Attribute;

use Attribute;

/**
 * Attribute to declare middleware metadata for auto-registration.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AgaviMiddleware
{
    public function __construct(
        public string $phase = 'pre',
        public int $priority = 0,
        public ?string $before = null,
        public ?string $after = null,
        public bool $enabled = true,
    ) {}
}
