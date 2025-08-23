<?php
/**
 * Simple Test Script for WP License Manager
 * This script can be run to test core functionality
 */

// Only run this if directly accessed with a secret parameter
if (!isset($_GET['test_wplm']) || $_GET['test_wplm'] !== 'run_tests') {
    die('Access denied. Use ?test_wplm=run_tests to run tests.');
}

// Load WordPress
if (!defined('ABSPATH')) {
    // Try to find WordPress root
    $wp_root = dirname(__FILE__);
    while (!file_exists($wp_root . '/wp-config.php') && $wp_root !== '/') {
        $wp_root = dirname($wp_root);
    }
    
    if (file_exists($wp_root . '/wp-config.php')) {
        require_once($wp_root . '/wp-config.php');
    } else {
        die('Could not find WordPress installation.');
    }
}

echo "<h1>WP License Manager Test Results</h1>";
echo "<style>body { font-family: Arial, sans-serif; margin: 20px; } .pass { color: green; } .fail { color: red; } .warning { color: orange; }</style>";

$tests_passed = 0;
$tests_total = 0;

function run_test($name, $test_function) {
    global $tests_passed, $tests_total;
    $tests_total++;
    
    echo "<h3>Testing: {$name}</h3>";
    
    try {
        $result = $test_function();
        if ($result === true) {
            echo "<p class='pass'>✓ PASSED</p>";
            $tests_passed++;
        } elseif (is_string($result)) {
            echo "<p class='warning'>⚠ WARNING: {$result}</p>";
        } else {
            echo "<p class='fail'>✗ FAILED</p>";
        }
    } catch (Exception $e) {
        echo "<p class='fail'>✗ FAILED: " . $e->getMessage() . "</p>";
    }
    
    echo "<hr>";
}

// Test 1: Check if classes exist
run_test("Core Classes Exist", function() {
    $required_classes = [
        'WP_License_Manager',
        'WPLM_CPT_Manager',
        'WPLM_Enhanced_Admin_Manager',
        'WPLM_API_Manager'
    ];
    
    foreach ($required_classes as $class) {
        if (!class_exists($class)) {
            throw new Exception("Class {$class} not found");
        }
    }
    
    return true;
});

// Test 2: Check database tables
run_test("Database Connectivity", function() {
    global $wpdb;
    
    if (!isset($wpdb) || !is_object($wpdb)) {
        throw new Exception("WordPress database object not available");
    }
    
    try {
        $test_query = $wpdb->get_var("SELECT 1");
        if ($test_query !== '1') {
            throw new Exception("Database connection failed");
        }
    } catch (Exception $e) {
        return "Database query failed - " . $e->getMessage();
    }
    
    return true;
});

// Test 3: Check post types are registered
run_test("Custom Post Types", function() {
    $post_types = ['wplm_license', 'wplm_product', 'wplm_subscription', 'wplm_activity_log'];
    
    foreach ($post_types as $post_type) {
        if (!post_type_exists($post_type)) {
            throw new Exception("Post type {$post_type} not registered");
        }
    }
    
    return true;
});

// Test 4: Check API key exists
run_test("API Key Configuration", function() {
    $api_key = get_option('wplm_api_key', '');
    
    if (empty($api_key)) {
        return "API key not set - will be generated on first use";
    }
    
    if (strlen($api_key) < 32) {
        throw new Exception("API key too short - security risk");
    }
    
    return true;
});

// Test 5: Check capabilities
run_test("User Capabilities", function() {
    $admin = get_role('administrator');
    
    if (!$admin) {
        throw new Exception("Administrator role not found");
    }
    
    $required_caps = ['manage_wplm_licenses', 'edit_wplm_licenses', 'create_wplm_licenses'];
    
    foreach ($required_caps as $cap) {
        if (!$admin->has_cap($cap)) {
            return "Capability {$cap} not set - may need plugin reactivation";
        }
    }
    
    return true;
});

// Test 6: Check WooCommerce compatibility
run_test("WooCommerce Compatibility", function() {
    if (!class_exists('WooCommerce')) {
        return "WooCommerce not installed - integration features will be disabled";
    }
    
    // Check if integration files exist
    $integration_file = WPLM_PLUGIN_DIR . 'includes/class-woocommerce-integration.php';
    if (!file_exists($integration_file)) {
        return "WooCommerce integration file not found - some features may not work";
    }
    
    return true;
});

// Test 7: Check file permissions
run_test("File Permissions", function() {
    $plugin_dir = WPLM_PLUGIN_DIR;
    
    if (!is_readable($plugin_dir)) {
        throw new Exception("Plugin directory not readable");
    }
    
    if (!is_writable(WP_CONTENT_DIR)) {
        return "WordPress content directory not writable - uploads may fail";
    }
    
    return true;
});

// Test 8: Check memory limit
run_test("System Requirements", function() {
    $memory_limit = ini_get('memory_limit');
    $memory_bytes = wp_convert_hr_to_bytes($memory_limit);
    
    if ($memory_bytes < 128 * 1024 * 1024) {
        return "Memory limit is {$memory_limit} - recommend at least 128MB";
    }
    
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        throw new Exception("PHP version " . PHP_VERSION . " is too old - requires PHP 7.4+");
    }
    
    return true;
});

echo "<h2>Test Summary</h2>";
echo "<p><strong>Passed:</strong> {$tests_passed}/{$tests_total} tests</p>";

if ($tests_passed === $tests_total) {
    echo "<p class='pass'><strong>✓ All tests passed! WP License Manager should work correctly.</strong></p>";
} elseif ($tests_passed >= $tests_total * 0.8) {
    echo "<p class='warning'><strong>⚠ Most tests passed. Check warnings above.</strong></p>";
} else {
    echo "<p class='fail'><strong>✗ Multiple tests failed. Plugin may not work correctly.</strong></p>";
}

echo "<p><em>Test completed at " . date('Y-m-d H:i:s') . "</em></p>";
echo "<p><strong>Note:</strong> Delete this test file (test-functionality.php) from production sites.</p>";
?>
