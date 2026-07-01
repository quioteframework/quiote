<?php
require('/Users/fgilcher/Sites/quiote/branches/felix-testing-implementation/Quiote/quiote.php');
require('../app/config.php');
Quiote::bootstrap('development');
Context::getInstance('web')->getController()->dispatch();

?>