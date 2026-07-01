<?php

use Quiote\Config\Config;

Config::set('core.testing_dir', realpath(__DIR__));
Config::set('core.app_dir', realpath(__DIR__.'/sandbox/app/'));
Config::set('core.config_dir', realpath(__DIR__.'/sandbox/app/config/'));
Config::set('core.cache_dir', Config::get('core.app_dir') . '/cache'); // for the clearCache() before bootstrap()
Config::set('core.default_context', 'web');

?>