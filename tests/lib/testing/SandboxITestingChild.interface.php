<?php

require_once __DIR__ . '/SandboxITestingParent1.interface.php';
require_once __DIR__ . '/SandboxITestingParent2.interface.php';

interface SandboxITestingChild extends SandboxITestingParent1, SandboxITestingParent2 {
	
}
