<?php

declare(strict_types=1);

namespace Quiote\Runtime;

use Quiote\Runtime\Emitter\SapiEmitter;

/**
 * The original SAPI emitter, kept as the name apps and tests already reference.
 * The implementation moved to {@see SapiEmitter} when response emission became
 * a per-runtime concern ({@see \Quiote\Runtime\Emitter\ResponseEmitterInterface});
 * new code should depend on that interface instead of this class.
 */
class HttpEmitter extends SapiEmitter
{
}
