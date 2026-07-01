<?php

error_reporting(E_ALL | E_STRICT);
require('../../src/quiote.php');
require('../app/config.php');
Quiote::bootstrap('development');
Context::getInstance('web')->getController()->dispatch();

?>