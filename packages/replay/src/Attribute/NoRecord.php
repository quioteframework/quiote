<?php

declare(strict_types=1);

namespace Quiote\Replay\Attribute;

use Attribute;

/**
 * Marks an action as non-recordable -- for an endpoint handling payment or
 * credentials, where a body's sensitive
 * field names are not known in advance and name-based redaction is not
 * enough. {@see \Quiote\Replay\Recording\RecorderMiddleware} keeps only the
 * metadata skeleton for a request whose resolved action carries this
 * attribute.
 *
 * Presence is the signal -- no constructor arguments -- matching the
 * "opt-in scan" idiom `Quiote\Middleware\Compiler\MiddlewareAttributeScanner`
 * already uses for `#[Middleware]`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class NoRecord
{
}
