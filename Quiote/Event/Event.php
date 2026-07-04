<?php

namespace Quiote\Event;

/**
 * Base class for framework/domain events dispatched through {@see Events}.
 *
 * A plain marker — it carries no state of its own; concrete events add their
 * own readonly payload. Extend {@see StoppableEvent} instead if listeners
 * should be able to halt propagation (PSR-14 stoppable semantics).
 *
 * This is deliberately separate from the request-pipeline middleware: middleware
 * models the HTTP request/response lifecycle, events model framework/domain
 * moments (kernel boot, route matched, action before/after, response sending)
 * that plugins and app code hook into without inserting themselves into the
 * PSR-15 stack.
 */
abstract class Event
{
}
