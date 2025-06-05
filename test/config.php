<?php

use Agavi\Config\AgaviConfig;

AgaviConfig::set('core.testing_dir', realpath(__DIR__));
AgaviConfig::set('core.app_dir', realpath(__DIR__.'/sandbox/app/'));
AgaviConfig::set('core.config_dir', realpath(__DIR__.'/sandbox/app/config/'));
AgaviConfig::set('core.cache_dir', AgaviConfig::get('core.app_dir') . '/cache'); // for the clearCache() before bootstrap()
AgaviConfig::set('core.default_context', 'web');

?>