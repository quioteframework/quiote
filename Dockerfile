FROM dunglas/frankenphp

# Install basic tools and PHP extensions that might be needed
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy the Agavi source
COPY . /app/

# Create a simple test structure
RUN mkdir -p /app/pub

# Copy our test files to the public directory
COPY debug_routing.php /app/pub/
COPY Caddyfile.test /app/Caddyfile

# Create a minimal index.php for testing
RUN echo '<?php\n\
// Minimal Agavi bootstrap for testing routing\n\
echo "<h1>Agavi Routing Test</h1>";\n\
echo "<p>If you see this via a clean URL (without index.php), routing is working!</p>";\n\
echo "<p><a href=\"/debug_routing\">Debug Routing Info</a></p>";\n\
echo "<hr>";\n\
echo "<h2>Server Info</h2>";\n\
echo "<table border=\"1\">";\n\
echo "<tr><th>Variable</th><th>Value</th></tr>";\n\
$vars = ["SERVER_SOFTWARE", "SCRIPT_NAME", "REQUEST_URI", "QUERY_STRING", "PATH_INFO"];\n\
foreach($vars as $var) {\n\
    $value = $_SERVER[$var] ?? "<em>not set</em>";\n\
    echo "<tr><td>$var</td><td>" . htmlspecialchars($value) . "</td></tr>";\n\
}\n\
echo "</table>";\n\
?>' > /app/pub/index.php

# Create a test routing configuration (simplified)
RUN echo '<?php\n\
// Simple test to verify the routing detection logic works\n\
require_once __DIR__ . "/../src/Util/AgaviToolkit.php";\n\
\n\
// Simulate the routing detection logic\n\
$requestUri = $_SERVER["REQUEST_URI"] ?? "";\n\
$ru = array_merge(["path" => "", "query" => ""], parse_url("scheme://authority" . $requestUri));\n\
$qs = $_SERVER["QUERY_STRING"] ?? "";\n\
\n\
// Apache detection\n\
$apacheRewriteDetected = (preg_replace("/&+$/D", "", (string) $qs) !== preg_replace("/&+$/D", "", (string) $ru["query"]));\n\
\n\
// Modern detection\n\
$modernRewriteDetected = false;\n\
if(isset($_SERVER["SCRIPT_NAME"]) && $_SERVER["SCRIPT_NAME"] !== "") {\n\
    $scriptName = $_SERVER["SCRIPT_NAME"];\n\
    $requestPath = $requestUri;\n\
    if(($pos = strpos($requestPath, "?")) !== false) {\n\
        $requestPath = substr($requestPath, 0, $pos);\n\
    }\n\
    $modernRewriteDetected = !str_contains($requestPath, $scriptName);\n\
}\n\
\n\
$rewritten = $apacheRewriteDetected || $modernRewriteDetected;\n\
\n\
echo "<h1>Agavi Routing Detection Test</h1>";\n\
echo "<h2>Detection Results</h2>";\n\
echo "<ul>";\n\
echo "<li>Apache Rewrite Detected: " . ($apacheRewriteDetected ? "YES" : "NO") . "</li>";\n\
echo "<li>Modern Rewrite Detected: " . ($modernRewriteDetected ? "YES" : "NO") . "</li>";\n\
echo "<li><strong>Final Result: " . ($rewritten ? "REWRITING ENABLED" : "REWRITING DISABLED") . "</strong></li>";\n\
echo "</ul>";\n\
echo "<h2>Test URLs</h2>";\n\
echo "<ul>";\n\
echo "<li><a href=\"/test_routing\">Clean URL Test (/test_routing)</a></li>";\n\
echo "<li><a href=\"/some/nested/path\">Nested Path Test (/some/nested/path)</a></li>";\n\
echo "<li><a href=\"/index.php/traditional/path\">Traditional Path (/index.php/traditional/path)</a></li>";\n\
echo "</ul>";\n\
?>' > /app/pub/test_routing.php

# Expose port 80 (Caddy default)
EXPOSE 80

# Start FrankenPHP with Caddy
CMD ["frankenphp", "run", "--config", "/app/Caddyfile"]
