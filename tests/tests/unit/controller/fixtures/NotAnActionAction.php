<?php

namespace Sandbox\Modules\ControllerTests\Actions;

/**
 * Deliberately does NOT implement Quiote\Action\Action. Used to exercise the
 * failure path of Controller::createActionInstance(), which must reject a
 * resolved class that doesn't satisfy the Action contract instead of
 * silently returning the wrong type.
 */
class NotAnActionAction
{
}
