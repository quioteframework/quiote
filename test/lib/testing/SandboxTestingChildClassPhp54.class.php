<?php
require_once __DIR__ . '/SandboxTestingParentClass.class.php';
require_once __DIR__ . '/SandboxTestingTrait.trait.php';
require_once __DIR__ . '/SandboxITestingChild.interface.php';

class SandboxTestingChildClass extends SandboxTestingParentClass implements SandboxITestingChild
{
	use SandboxTestingTrait;
}
