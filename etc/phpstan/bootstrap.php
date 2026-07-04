<?php

// Kernel.php defines this dynamically based on the runtime APCu availability
// (function_exists('apcu_enabled') && apcu_enabled()), so its value varies by
// deployment. We only need PHPStan to know the constant exists with type bool;
// see the `dynamicConstantNames` entry in phpstan.neon for why it isn't treated
// as the literal value defined here.
if (!defined('QUIOTE_USE_APCU_CONFIG_CACHE')) {
    define('QUIOTE_USE_APCU_CONFIG_CACHE', false);
}
