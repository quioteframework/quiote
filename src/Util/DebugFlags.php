<?php

namespace Agavi\Util;

/**
 * Caches AGAVI_DEBUG_* environment flags as static booleans.
 *
 * Call DebugFlags::init() once at bootstrap (AgaviContext::initialize()).
 * All hot-path code reads the public static fields directly — no getenv()
 * overhead on every request in FrankenPHP worker mode.
 *
 * @package    agavi
 * @subpackage util
 */
final class DebugFlags
{
    public static bool $routing     = false;
    public static bool $security    = false;
    public static bool $auth        = false;
    public static bool $session     = false;
    public static bool $dispatch    = false;
    public static bool $response    = false;
    public static bool $cookie      = false;
    public static bool $validation  = false;
    public static bool $exec        = false;
    public static bool $database    = false;
    public static bool $user        = false;
    public static bool $shutdown    = false;
    public static bool $request     = false;
    public static bool $requestDiag = false;
    public static bool $reset             = false;
    public static bool $view              = false;
    public static bool $forward           = false;
    public static bool $slotDispatch      = false;
    public static bool $slotExceptions    = false;
    public static bool $slotRenderer      = false;
    public static bool $exceptionTemplate = false;

    private static bool $initialized = false;

    /**
     * Read all AGAVI_DEBUG_* env vars once and cache them in static fields.
     * Calling this more than once is a no-op (idempotent).
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$routing     = (bool)getenv('AGAVI_DEBUG_ROUTING');
        self::$security    = (bool)getenv('AGAVI_DEBUG_SECURITY');
        self::$auth        = (bool)getenv('AGAVI_DEBUG_AUTH');
        self::$session     = (bool)getenv('AGAVI_DEBUG_SESSION');
        self::$dispatch    = (bool)getenv('AGAVI_DEBUG_DISPATCH');
        self::$response    = (bool)getenv('AGAVI_DEBUG_RESPONSE');
        self::$cookie      = (bool)getenv('AGAVI_DEBUG_COOKIE');
        self::$validation  = (bool)getenv('AGAVI_DEBUG_VALIDATION');
        self::$exec        = (bool)getenv('AGAVI_DEBUG_EXEC');
        self::$database    = (bool)getenv('AGAVI_DEBUG_DATABASE');
        self::$user        = (bool)getenv('AGAVI_DEBUG_USER');
        self::$shutdown    = (bool)getenv('AGAVI_DEBUG_SHUTDOWN');
        self::$request     = (bool)getenv('AGAVI_DEBUG_REQUEST');
        self::$requestDiag = (bool)getenv('AGAVI_DEBUG_REQUEST_DIAG');
        self::$reset             = (bool)getenv('AGAVI_DEBUG_RESET');
        self::$view              = (bool)getenv('AGAVI_DEBUG_VIEW');
        self::$forward           = (bool)getenv('AGAVI_DEBUG_FORWARD');
        self::$slotDispatch      = (bool)getenv('AGAVI_DEBUG_SLOT_DISPATCH');
        self::$slotExceptions    = (bool)getenv('AGAVI_DEBUG_SLOT_EXCEPTIONS');
        self::$slotRenderer      = (bool)getenv('AGAVI_DEBUG_SLOT_RENDERER');
        self::$exceptionTemplate = (bool)getenv('AGAVI_DEBUG_EXCEPTION_TEMPLATE');

        self::$initialized = true;
    }

    /**
     * Re-read env vars from the current environment (useful in tests that
     * change env vars at runtime, or when reloading config in non-worker mode).
     */
    public static function reinit(): void
    {
        self::$initialized = false;
        self::init();
    }
}
