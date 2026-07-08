<?php

namespace Sandbox\Modules\ControllerTests\Views;

/**
 * Deliberately does NOT implement Quiote\View\View. Used to exercise the
 * failure path of Controller::createViewInstance(), which must reject a
 * resolved class that doesn't satisfy the View contract instead of
 * silently returning the wrong type.
 */
class NotAViewView
{
}
