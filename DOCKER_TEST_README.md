# Quiote FrankenPHP Routing Test

This Docker setup provides a minimal test environment to verify that the Quiote routing fixes work correctly with FrankenPHP and Caddy.

## Quick Start

1. **Build and run the test container:**
   ```bash
   docker-compose up --build
   ```

2. **Access the test application:**
   - Main test: http://localhost:8080/
   - Debug info: http://localhost:8080/debug_routing
   - Comprehensive test: http://localhost:8080/comprehensive_test

3. **Test clean URLs:**
   - http://localhost:8080/test_clean_url
   - http://localhost:8080/some/nested/path
   - http://localhost:8080/debug_routing (without .php)

## What to Look For

### ✅ Success Indicators
- "Modern Rewrite Detected: YES" in the debug output
- Clean URLs work without `/index.php`
- The comprehensive test shows "SUCCESS: URL Rewriting Detected!"

### ❌ Issues to Check
- "Modern Rewrite Detected: NO" means routing detection failed
- Clean URLs return 404 errors
- All URLs include `/index.php`

## Test Files

- **`Dockerfile`** - FrankenPHP container with Caddy
- **`Caddyfile.test`** - Caddy configuration with URL rewriting
- **`docker-compose.yml`** - Easy container management
- **`pub/comprehensive_test.php`** - Detailed routing analysis
- **`debug_routing.php`** - Simple debug information

## Configuration

The test uses this Caddy configuration:
```caddy
:80 {
    root * /app/pub
    php_server
    try_files {path} {path}/ /index.php?{query}
    file_server
}
```

This setup:
- Serves files from `/app/pub`
- Enables FrankenPHP processing
- Rewrites missing files to `/index.php`
- Serves static files directly

## Troubleshooting

### Container won't start
```bash
# Check logs
docker-compose logs quiote-test

# Rebuild completely
docker-compose down
docker-compose up --build --force-recreate
```

### Routing not detected
1. Check that you're accessing clean URLs (without `.php`)
2. Verify Caddy configuration is correct
3. Look at the "URL Parsing Breakdown" in the test output

### 404 errors on clean URLs
- The Caddy rewrite rules may not be working
- Check the Caddyfile configuration
- Ensure FrankenPHP is properly handling requests

## Integration with Your App

Once the test shows routing detection is working:

1. **Replace the test files** with your actual Quiote application
2. **Update the Caddyfile** to match your app structure
3. **Test your routes** to ensure they work with clean URLs
4. **Verify URL generation** produces clean URLs without `index.php`

## Files Structure

```
/app/
├── src/                    # Quiote source code
├── pub/                    # Public web directory
│   ├── index.php          # Test entry point
│   ├── debug_routing.php  # Debug script
│   └── comprehensive_test.php # Detailed test
├── Caddyfile.test         # Caddy configuration
└── Dockerfile             # Container definition
```

## Next Steps

After confirming the routing detection works:

1. Integrate your full Quiote application
2. Test all your routes with clean URLs
3. Verify that generated URLs are clean
4. Deploy to your production environment

The routing fixes should now work correctly with FrankenPHP!
