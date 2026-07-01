<?php

ini_set('soap.wsdl_cache_enabled', 0);
require('../../src/quiote.php');
require('../app/config.php');
Quiote::bootstrap('development');
Context::getInstance('soap')->getController()->dispatch();

?>