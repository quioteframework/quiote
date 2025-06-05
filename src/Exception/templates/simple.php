<?php 
echo "Exception details: " . $e->getMessage() . "\n"; 
echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
echo "Trace:\n" . $e->getTraceAsString() . "\n";
echo "Config values:\n";
echo "core.agavi_dir = " . AgaviConfig::get("core.agavi_dir") . "\n";
echo "core.app_dir = " . AgaviConfig::get("core.app_dir") . "\n";
echo "exception.default_template = " . AgaviConfig::get("exception.default_template") . "\n";
?>