<?php 
use Quiote\Config\Config;

echo "Exception details: " . $e->getMessage() . "\n"; 
echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
echo "Trace:\n" . $e->getTraceAsString() . "\n";
echo "Config values:\n";
echo "core.quiote_dir = " . Config::get("core.quiote_dir") . "\n";
echo "core.app_dir = " . Config::get("core.app_dir") . "\n";
echo "exception.default_template = " . Config::get("exception.default_template") . "\n";
?>