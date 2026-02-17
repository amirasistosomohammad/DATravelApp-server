<?php
/**
 * Quick diagnostic script to check if GD extension is enabled
 * Access via: http://localhost:8000/check-gd.php
 * DELETE THIS FILE AFTER TESTING FOR SECURITY
 */

header('Content-Type: text/plain');

echo "=== PHP GD Extension Check ===\n\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "PHP SAPI: " . php_sapi_name() . "\n";
echo "PHP Config File: " . php_ini_loaded_file() . "\n\n";

echo "GD Extension Status: " . (extension_loaded('gd') ? 'ENABLED ✓' : 'DISABLED ✗') . "\n\n";

if (extension_loaded('gd')) {
    $gdInfo = gd_info();
    echo "GD Version: " . ($gdInfo['GD Version'] ?? 'Unknown') . "\n";
    echo "Supported Formats:\n";
    foreach (['PNG Support', 'JPEG Support', 'GIF Support', 'WebP Support'] as $format) {
        echo "  - $format: " . (isset($gdInfo[$format]) && $gdInfo[$format] ? 'Yes' : 'No') . "\n";
    }
} else {
    echo "\n⚠️  GD extension is NOT loaded!\n";
    echo "\nTo enable GD in XAMPP:\n";
    echo "1. Open: " . php_ini_loaded_file() . "\n";
    echo "2. Find: ;extension=gd\n";
    echo "3. Remove the semicolon: extension=gd\n";
    echo "4. Save and RESTART Apache/XAMPP\n";
    echo "5. Restart 'php artisan serve' if using Laravel's built-in server\n";
}
