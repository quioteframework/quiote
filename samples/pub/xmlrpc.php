<?php
require('../../src/quiote.php');
require('../app/config.php');
Quiote::bootstrap('development');
Context::getInstance('xmlrpc')->getController()->dispatch();

?>