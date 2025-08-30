<?php
// Test script to verify WP-Cron is working
// Upload this as test-cron.php to your WordPress root directory
// Then run: curl https://yourdomain.com/test-cron.php

// Load WordPress
require_once('wp-config.php');
require_once('wp-load.php');

echo "=== WP-Cron Test Results ===\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n";

// Check if DeepSeek Translate cron is scheduled
$scheduled = wp_next_scheduled('dst_process_translation_queue');
if ($scheduled) {
    echo "✓ DeepSeek Translate cron is scheduled for: " . date('Y-m-d H:i:s', $scheduled) . "\n";
} else {
    echo "✗ DeepSeek Translate cron is NOT scheduled\n";
}

// Check translation queue
$queue = get_option('dst_translation_queue', []);
echo "Translation queue length: " . count($queue) . " items\n";

// Test running cron manually
echo "\n=== Running WP-Cron Manually ===\n";
$cron_url = site_url('wp-cron.php?doing_wp_cron');
$response = wp_remote_get($cron_url, ['timeout' => 30]);

if (is_wp_error($response)) {
    echo "✗ Cron execution failed: " . $response->get_error_message() . "\n";
} else {
    echo "✓ Cron executed successfully\n";
    echo "Response code: " . wp_remote_retrieve_response_code($response) . "\n";
}

echo "\n=== Cleanup ===\n";
echo "Delete this file after testing for security!\n";
?>
