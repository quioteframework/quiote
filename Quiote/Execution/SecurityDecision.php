<?php
namespace Quiote\Execution;

/**
 * The outcome of the security check for an action: run it, or forward somewhere else.
 *
 * Produced by {@see SecurityService} and {@see \Quiote\Middleware\SecurityMiddleware} and
 * recorded on {@see ExecutionState::$securityDecision}, where the cache layer stores and
 * replays it with the rest of the execution state.
 *
 * `Allow` means the action is not secure, security is globally disabled, or the user
 * satisfied it. `LoginForward` means the user is not authenticated and the configured login
 * action is dispatched instead. `SecureForward` means the user is authenticated but lacks a
 * credential the action requires, and the configured secure action is dispatched instead.
 */
enum SecurityDecision: string { case Allow = 'allow'; case LoginForward = 'login'; case SecureForward = 'secure'; }
