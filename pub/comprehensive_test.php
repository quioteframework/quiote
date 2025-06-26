<?php
/**
 * Comprehensive Agavi Routing Test with FrankenPHP
 * 
 * This test script validates that the routing detection logic works correctly
 * with FrankenPHP and modern web servers.
 */

// Try to load Agavi classes if available
$agaviAvailable = false;
if (file_exists(__DIR__ . '/../src/Util/AgaviToolkit.php')) {
    require_once __DIR__ . '/../src/Util/AgaviToolkit.php';
    $agaviAvailable = true;
}

echo "<h1>Agavi + FrankenPHP Routing Test</h1>\n";

echo "<h2>Environment Information</h2>\n";
echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
echo "<tr><th style='padding: 8px;'>Variable</th><th style='padding: 8px;'>Value</th></tr>\n";

$serverVars = [
    'SERVER_SOFTWARE',
    'SCRIPT_NAME', 
    'REQUEST_URI',
    'QUERY_STRING',
    'PATH_INFO',
    'HTTP_X_REWRITE_URL',
    'GATEWAY_INTERFACE',
    'DOCUMENT_ROOT',
    'SCRIPT_FILENAME'
];

foreach($serverVars as $var) {
    $value = $_SERVER[$var] ?? '<em style="color: #888;">not set</em>';
    echo "<tr><td style='padding: 8px; font-weight: bold;'>$var</td><td style='padding: 8px;'>" . htmlspecialchars($value) . "</td></tr>\n";
}
echo "</table>\n";

echo "<h2>Routing Detection Analysis</h2>\n";

// Simulate the exact logic from AgaviWebRouting.php
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$ru = array_merge(['path' => '', 'query' => ''], parse_url('scheme://authority' . $requestUri));

$qs = $_SERVER['QUERY_STRING'] ?? '';

// Apache detection (original logic)
$apacheRewriteDetected = (preg_replace('/&+$/D', '', (string) $qs) !== preg_replace('/&+$/D', '', (string) $ru['query']));

// Modern server detection (new logic)
$modernRewriteDetected = false;
if(isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '') {
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $requestPath = $requestUri;
    if(($pos = strpos($requestPath, '?')) !== false) {
        $requestPath = substr($requestPath, 0, $pos);
    }
    $modernRewriteDetected = !str_contains($requestPath, $scriptName);
}

$rewritten = $apacheRewriteDetected || $modernRewriteDetected;

echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
echo "<tr><th style='padding: 8px;'>Detection Method</th><th style='padding: 8px;'>Result</th><th style='padding: 8px;'>Details</th></tr>\n";
echo "<tr style='background-color: #f9f9f9;'><td style='padding: 8px;'>Apache Rewrite</td><td style='padding: 8px; color: " . ($apacheRewriteDetected ? 'green' : 'red') . ";'>" . ($apacheRewriteDetected ? '✓ YES' : '✗ NO') . "</td><td style='padding: 8px;'>Compares QUERY_STRING vs parsed REQUEST_URI query</td></tr>\n";
echo "<tr><td style='padding: 8px;'>Modern Rewrite</td><td style='padding: 8px; color: " . ($modernRewriteDetected ? 'green' : 'red') . ";'>" . ($modernRewriteDetected ? '✓ YES' : '✗ NO') . "</td><td style='padding: 8px;'>Checks if SCRIPT_NAME is missing from REQUEST_URI path</td></tr>\n";
echo "<tr style='background-color: " . ($rewritten ? '#e8f5e8' : '#ffe8e8') . "; font-weight: bold;'><td style='padding: 8px;'>Final Result</td><td style='padding: 8px; color: " . ($rewritten ? 'green' : 'red') . ";'>" . ($rewritten ? '✓ REWRITING ENABLED' : '✗ REWRITING DISABLED') . "</td><td style='padding: 8px;'>Combined result determines routing behavior</td></tr>\n";
echo "</table>\n";

echo "<h2>URL Parsing Breakdown</h2>\n";
echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
echo "<tr><th style='padding: 8px;'>Component</th><th style='padding: 8px;'>Value</th><th style='padding: 8px;'>Source</th></tr>\n";
echo "<tr><td style='padding: 8px;'>Original REQUEST_URI</td><td style='padding: 8px;'>" . htmlspecialchars($requestUri) . "</td><td style='padding: 8px;'>\$_SERVER['REQUEST_URI']</td></tr>\n";
echo "<tr style='background-color: #f9f9f9;'><td style='padding: 8px;'>Parsed path</td><td style='padding: 8px;'>" . htmlspecialchars($ru['path']) . "</td><td style='padding: 8px;'>parse_url() result</td></tr>\n";
echo "<tr><td style='padding: 8px;'>Parsed query</td><td style='padding: 8px;'>" . htmlspecialchars($ru['query']) . "</td><td style='padding: 8px;'>parse_url() result</td></tr>\n";
echo "<tr style='background-color: #f9f9f9;'><td style='padding: 8px;'>QUERY_STRING</td><td style='padding: 8px;'>" . htmlspecialchars($qs) . "</td><td style='padding: 8px;'>\$_SERVER['QUERY_STRING']</td></tr>\n";
echo "</table>\n";

if($modernRewriteDetected) {
    echo "<h3>Modern Rewrite Path Calculation</h3>\n";
    $requestPath = $requestUri;
    if(($pos = strpos($requestPath, '?')) !== false) {
        $requestPath = substr($requestPath, 0, $pos);
    }
    
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    if($scriptDir === '.' || $scriptDir === '/') {
        $scriptDir = '';
    }
    
    $input = $requestPath;
    if($scriptDir !== '' && str_starts_with($requestPath, $scriptDir)) {
        $input = substr($requestPath, strlen($scriptDir));
    }
    
    if(!str_starts_with($input, '/')) {
        $input = '/' . $input;
    }
    
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
    echo "<tr><th style='padding: 8px;'>Calculation Step</th><th style='padding: 8px;'>Value</th></tr>\n";
    echo "<tr><td style='padding: 8px;'>Request path (no query)</td><td style='padding: 8px;'>" . htmlspecialchars($requestPath) . "</td></tr>\n";
    echo "<tr style='background-color: #f9f9f9;'><td style='padding: 8px;'>Script directory</td><td style='padding: 8px;'>" . htmlspecialchars($scriptDir ?: '/') . "</td></tr>\n";
    echo "<tr><td style='padding: 8px;'>Calculated input path</td><td style='padding: 8px; color: blue; font-weight: bold;'>" . htmlspecialchars($input) . "</td></tr>\n";
    echo "</table>\n";
}

echo "<h2>Test Results Summary</h2>\n";
echo "<div style='padding: 15px; margin: 20px 0; border-radius: 5px; " . ($rewritten ? "background-color: #e8f5e8; border: 2px solid #4caf50;" : "background-color: #ffe8e8; border: 2px solid #f44336;") . "'>\n";
if($rewritten) {
    echo "<h3 style='color: #2e7d32; margin-top: 0;'>✓ SUCCESS: URL Rewriting Detected!</h3>\n";
    echo "<p><strong>Your FrankenPHP setup is working correctly.</strong> The routing detection logic successfully identified that clean URLs are being processed.</p>\n";
    echo "<p>This means:</p>\n";
    echo "<ul>\n";
    echo "<li>URLs like <code>/some/path</code> will work without <code>index.php</code></li>\n";
    echo "<li>Agavi routing will generate clean URLs</li>\n";
    echo "<li>The updated routing code is functioning as expected</li>\n";
    echo "</ul>\n";
} else {
    echo "<h3 style='color: #c62828; margin-top: 0;'>⚠ NOTICE: No URL Rewriting Detected</h3>\n";
    echo "<p><strong>The system will fall back to traditional <code>index.php</code> URLs.</strong></p>\n";
    echo "<p>Possible reasons:</p>\n";
    echo "<ul>\n";
    echo "<li>You accessed this page directly (e.g., <code>/debug_routing.php</code>)</li>\n";
    echo "<li>Caddy rewrite rules may not be configured correctly</li>\n";
    echo "<li>The web server is not performing URL rewriting</li>\n";
    echo "</ul>\n";
}
echo "</div>\n";

echo "<h2>Interactive Test Links</h2>\n";
echo "<p>Test the routing detection with different URL patterns:</p>\n";
echo "<div style='background-color: #f5f5f5; padding: 15px; border-radius: 5px;'>\n";
echo "<h4>Traditional URLs (with script name):</h4>\n";
echo "<ul>\n";
echo "<li><a href='" . htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '/index.php') . "' target='_blank'>Main page with script name</a></li>\n";
echo "<li><a href='" . htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '/debug_routing.php') . "' target='_blank'>This debug page (traditional)</a></li>\n";
echo "</ul>\n";

echo "<h4>Clean URLs (without script name):</h4>\n";
echo "<ul>\n";
$basePath = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if($basePath === '.' || $basePath === '/') $basePath = '';
echo "<li><a href='" . htmlspecialchars($basePath . '/') . "' target='_blank'>Root clean URL</a></li>\n";
echo "<li><a href='" . htmlspecialchars($basePath . '/test_clean_url') . "' target='_blank'>Test clean URL</a></li>\n";
echo "<li><a href='" . htmlspecialchars($basePath . '/some/nested/path') . "' target='_blank'>Nested clean URL</a></li>\n";
echo "<li><a href='" . htmlspecialchars($basePath . '/debug_routing') . "' target='_blank'>This debug page (clean URL)</a></li>\n";
echo "</ul>\n";
echo "</div>\n";

if($agaviAvailable) {
    echo "<h2>Agavi Integration Status</h2>\n";
    echo "<div style='background-color: #e8f5e8; padding: 15px; border-radius: 5px; border: 2px solid #4caf50;'>\n";
    echo "<p><strong>✓ Agavi classes are available!</strong> The routing fixes can be tested with your actual Agavi application.</p>\n";
    echo "</div>\n";
} else {
    echo "<h2>Agavi Integration Status</h2>\n";
    echo "<div style='background-color: #fff3cd; padding: 15px; border-radius: 5px; border: 2px solid #ffc107;'>\n";
    echo "<p><strong>⚠ Agavi classes not loaded.</strong> This is a standalone test. To test with your full Agavi application, ensure the framework is properly bootstrapped.</p>\n";
    echo "</div>\n";
}

echo "<h2>Next Steps</h2>\n";
echo "<div style='background-color: #e3f2fd; padding: 15px; border-radius: 5px; border: 2px solid #2196f3;'>\n";
echo "<ol>\n";
echo "<li><strong>Verify the test results above</strong> - If rewriting is detected, your setup is working</li>\n";
echo "<li><strong>Test with your actual Agavi application</strong> - Replace this test with your real app</li>\n";
echo "<li><strong>Check generated URLs</strong> - Ensure Agavi generates clean URLs without index.php</li>\n";
echo "<li><strong>Test routing functionality</strong> - Verify that your routes work with clean URLs</li>\n";
echo "</ol>\n";
echo "</div>\n";

echo "<footer style='margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 0.9em;'>\n";
echo "<p>Generated at " . date('Y-m-d H:i:s') . " | FrankenPHP + Caddy + Agavi Routing Test</p>\n";
echo "</footer>\n";

?>
