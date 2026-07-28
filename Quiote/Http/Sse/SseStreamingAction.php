<?php

namespace Quiote\Http\Sse;

use Quiote\Request\WebRequest;

/**
 * Actions implementing this interface bypass the normal Action/View
 * dispatch entirely -- DispatchMiddleware detects it and streams the
 * returned events directly as a `text/event-stream` response, with no
 * caching, validation short-circuiting, or View involved.
 */
interface SseStreamingAction
{
    /**
     * @return iterable<SseEvent|string>
     */
    public function streamEvents(WebRequest $request): iterable;
}
