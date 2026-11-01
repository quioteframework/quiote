<?php

declare(strict_types=1);

namespace Quiote\Error;

use Quiote\Logging\Log;

/**
 * The exceptions the current request handled itself, in the order they were reported.
 *
 * Request-scoped in the container, so the list starts empty for every request and a persistent
 * worker drops it at the request boundary. It is deliberately not a request attribute: middleware
 * that republishes the request it received after the action has run would discard an attribute
 * the action added, and the `Throwable` attribute `ErrorHandlingMiddleware` publishes already
 * means "this request ended in this exception", which handled exceptions must not be mistaken for.
 *
 * Report through {@see ErrorReporting}, which a singleton can inject; read here.
 */
final class HandledExceptions
{
    /** Reports beyond this many in one request are dropped; each holds its exception and trace. */
    public const int MAX = 50;

    /** @var list<HandledException> */
    private array $entries = [];

    private int $dropped = 0;

    public function add(HandledException $entry): void
    {
        if (count($this->entries) >= self::MAX) {
            if ($this->dropped++ === 0) {
                Log::for($this)->warning(sprintf(
                    'More than %d handled exceptions were reported for this request; %s and any later ones are not kept.',
                    self::MAX,
                    $entry->exception::class,
                ));
            }

            return;
        }
        $this->entries[] = $entry;
    }

    /** @return list<HandledException> */
    public function all(): array
    {
        return $this->entries;
    }
}
