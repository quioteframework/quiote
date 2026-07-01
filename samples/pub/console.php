<?php

error_reporting(E_ALL | E_STRICT);
require(__DIR__ . '/../../src/quiote.php');
require(__DIR__ . '/../app/config.php');
Quiote::bootstrap('development');
Context::getInstance('console')->getController()->dispatch();

?>