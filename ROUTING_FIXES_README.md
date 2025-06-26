# Agavi Routing Fixes for FrankenPHP and Modern Web Servers

## Problem Description

The original `AgaviWebRouting.php` had Apache-specific logic for detecting URL rewriting and handling clean URLs. This caused issues when switching from Apache+PHP-FPM to FrankenPHP (or other modern web servers like Nginx/Caddy) because:

1. The rewrite detection logic only looked for Apache-specific behavior
2. URL path extraction assumed Apache's specific handling of `$_SERVER` variables
3. URL generation always fell back to `index.php` when routing detection failed

## Changes Made

### 1. Enhanced Rewrite Detection (`src/Routing/AgaviWebRouting.php`)

**Before:**
```php
$rewritten = (preg_replace('/&+$/D', '', (string) $qs) !== preg_replace('/&+$/D', '', (string) $ru['query']));
```

**After:**
```php
// Keep original Apache detection
$apacheRewriteDetected = (preg_replace('/&+$/D', '', (string) $qs) !== preg_replace('/&+$/D', '', (string) $ru['query']));

// Add modern server detection
$this->modernRewriteDetected = false;
if(isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '') {
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Remove query string from request URI for comparison
    $requestPath = $requestUri;
    if(($pos = strpos($requestPath, '?')) !== false) {
        $requestPath = substr($requestPath, 0, $pos);
    }
    
    // If the script name is not in the request path, we likely have URL rewriting
    $this->modernRewriteDetected = !str_contains($requestPath, $scriptName);
}

$rewritten = $apacheRewriteDetected || $this->modernRewriteDetected;
```

### 2. Improved Path Extraction for Modern Servers

Added a new branch for FrankenPHP and modern servers:

```php
} elseif($this->modernRewriteDetected) {
    // For FrankenPHP and modern servers with clean URL rewriting
    // Extract the input path by removing the base path from REQUEST_URI
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $requestPath = $requestUri;
    
    // Remove query string
    if(($pos = strpos($requestPath, '?')) !== false) {
        $requestPath = substr($requestPath, 0, $pos);
    }
    
    // Get the directory of the script
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    if($scriptDir === '.' || $scriptDir === '/') {
        $scriptDir = '';
    }
    
    // Remove the script directory to get the input path
    if($scriptDir !== '' && str_starts_with($requestPath, $scriptDir)) {
        $this->input = substr($requestPath, strlen($scriptDir));
    } else {
        $this->input = $requestPath;
    }
    
    // Ensure input starts with /
    if(!str_starts_with($this->input, '/')) {
        $this->input = '/' . $this->input;
    }
}
```

### 3. Enhanced URL Generation

Updated URL generation to prefer clean URLs when modern rewriting is detected:

```php
if(!isset($path)) {
    // the route does not exist. we generate a normal index.php?foo=bar URL.
    // However, for modern servers with URL rewriting, we might want to avoid index.php
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // If we detected modern rewriting earlier, try to use a clean path
    if($this->modernRewriteDetected && $scriptName !== '') {
        // Use the directory part of the script name without the script file itself
        $scriptDir = dirname($scriptName);
        $path = ($scriptDir === '/' || $scriptDir === '.') ? '/' : $scriptDir . '/';
    } else {
        // Traditional fallback with script name
        $path = $scriptName;
    }
}
```

## Testing the Changes

### 1. Use the Debug Script

Place the provided `debug_routing.php` file in your web root and access it through your browser. This will show you:

- Server environment variables
- Which routing detection method is working
- How URLs are being parsed
- Test links to verify behavior

### 2. Example Caddyfile Configuration

Use the provided `Caddyfile.example` as a starting point for your FrankenPHP configuration:

```caddy
your-domain.local {
    root * /path/to/your/agavi/pub
    php_server
    try_files {path} {path}/ /index.php?{query}
    file_server
}
```

### 3. Expected Behavior

With the changes:

1. **FrankenPHP/Caddy**: Should detect modern rewriting and generate clean URLs without `index.php`
2. **Apache**: Should continue to work as before using the original detection logic
3. **Nginx**: Should work with the modern detection if configured properly
4. **Fallback**: If detection fails, falls back to traditional `index.php` URLs

## Debugging Tips

1. **Check the debug script output** - It will tell you which detection method is working
2. **Verify your web server configuration** - Make sure URL rewriting is properly configured
3. **Test with different URL patterns** - Try both `/path/to/action` and `/index.php/path/to/action`
4. **Check server variables** - Ensure `$_SERVER['SCRIPT_NAME']` and `$_SERVER['REQUEST_URI']` have expected values

## Key Benefits

1. **Server agnostic**: Works with Apache, FrankenPHP, Nginx, Caddy
2. **Backward compatible**: Original Apache detection still works
3. **Clean URLs**: Generates cleaner URLs when modern rewriting is detected
4. **Robust fallback**: Still works even if detection fails

## Migration from Apache to FrankenPHP

1. Update your `AgaviWebRouting.php` with the provided changes
2. Configure your Caddyfile with proper URL rewriting (use the example)
3. Test with the debug script to verify detection is working
4. Deploy and test your application

The routing should now work properly with FrankenPHP while maintaining compatibility with Apache and other web servers.
