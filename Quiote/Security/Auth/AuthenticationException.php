<?php
namespace Quiote\Security\Auth;

use RuntimeException;

/**
 * Thrown by an {@see AuthenticatorInterface} when a presented credential
 * (password, Basic header, bearer token, ...) fails to establish an
 * identity. Caught by `Quiote\Security\Auth\AuthenticationManager`
 * (`packages/auth`) and routed to the matching firewall's
 * {@see EntryPointInterface}.
 * @since      1.0.0
 */
class AuthenticationException extends RuntimeException
{
}
