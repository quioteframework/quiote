<?php
// Secondary bootstrap entry point kept for backward compatibility.
// Some tooling historically expects test/config/bootstrap.php relative to phpunit.xml.
// Delegate to the canonical test/bootstrap.php.
require_once __DIR__ . '/../bootstrap.php';
