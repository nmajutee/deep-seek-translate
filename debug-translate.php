<?php
/**
 * Debug script for DeepSeek Translate plugin
 * Upload this to your WordPress root and run: yoursite.com/debug-translate.php
 * Delete after use for security!
 */

// Load WordPress
require_once('wp-config.php');
require_once('wp-load.php');

if (!current_user_can('administrator')) {
    die('Access denied. Must be admin.');
}

echo "<h2>DeepSeek Translate Debug Report</h2>";
echo "<p>Generated: " . date('Y-m-d H:i:s') . "</p>";

// Check if plugin is active
$active_plugins = get_option('active_plugins', []);
$is_active = false;
foreach ($active_plugins as $plugin) {
    if (strpos($plugin, 'deep-seek-translate') !== false) {
        $is_active = true;
        break;
    }
}

echo "<h3>Plugin Status</h3>";
echo $is_active ? "✅ Plugin is ACTIVE<br>" : "❌ Plugin is NOT ACTIVE<br>";

// Check for PHP errors
echo "<h3>PHP Environment</h3>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Memory Limit: " . ini_get('memory_limit') . "<br>";
echo "Max Execution Time: " . ini_get('max_execution_time') . " seconds<br>";

// Check plugin settings
echo "<h3>Plugin Settings</h3>";
$settings = get_option('dst_settings', []);
if (empty($settings)) {
    echo "❌ No settings found. Plugin may not be configured.<br>";
} else {
    echo "✅ Settings found:<br>";
    echo "- API Key: " . (empty($settings['api_key']) ? '❌ MISSING' : '✅ Set') . "<br>";
    echo "- API Model: " . ($settings['api_model'] ?? 'NOT SET') . "<br>";
    echo "- Background Translation: " . (!empty($settings['translate_in_background']) ? '✅ Enabled' : '❌ Disabled') . "<br>";
    echo "- Debug Mode: " . (!empty($settings['debug_mode']) ? '✅ Enabled' : '❌ Disabled') . "<br>";
    echo "- API Disabled: " . (!empty($settings['disable_api']) ? '⚠️ Yes (testing mode)' : '✅ No') . "<br>";
}

// Check translation queue
echo "<h3>Translation Queue</h3>";
$queue = get_option('dst_translation_queue', []);
echo "Queue size: " . count($queue) . " jobs<br>";

// Check cron status
echo "<h3>WP-Cron Status</h3>";
$scheduled = wp_next_scheduled('dst_process_translation_queue');
if ($scheduled) {
    echo "✅ Cron scheduled for: " . date('Y-m-d H:i:s', $scheduled) . "<br>";
} else {
    echo "❌ Cron NOT scheduled<br>";
}

// Check for recent errors
echo "<h3>Recent API Errors</h3>";
$last_error = get_transient('dst_api_error');
if ($last_error) {
    echo "⚠️ Recent error: " . esc_html($last_error) . "<br>";
} else {
    echo "✅ No recent API errors<br>";
}

// WordPress debug info
echo "<h3>WordPress Debug</h3>";
echo "WP_DEBUG: " . (defined('WP_DEBUG') && WP_DEBUG ? '✅ Enabled' : '❌ Disabled') . "<br>";
echo "WP_DEBUG_LOG: " . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? '✅ Enabled' : '❌ Disabled') . "<br>";

// Test basic functionality
echo "<h3>Basic Functionality Test</h3>";
try {
    if (class_exists('DST_DeepSeek_Translate')) {
        echo "✅ Plugin class exists<br>";
        
        // Test language detection
        $lang = DST_DeepSeek_Translate::get_current_lang();
        echo "Current language: " . ($lang ?: 'default') . "<br>";
        
    } else {
        echo "❌ Plugin class NOT found<br>";
    }
} catch (Exception $e) {
    echo "❌ Error testing functionality: " . $e->getMessage() . "<br>";
}

echo "<hr><p><strong>Instructions:</strong><br>";
echo "1. If API key is missing, go to Settings > DeepSeek Translate<br>";
echo "2. If cron is not scheduled, deactivate and reactivate the plugin<br>";
echo "3. If errors persist, enable Debug Mode in plugin settings<br>";
echo "4. Check error logs in wp-content/debug.log if WP_DEBUG_LOG is enabled<br>";
echo "5. <strong>DELETE THIS FILE after debugging for security!</strong></p>";
?>
