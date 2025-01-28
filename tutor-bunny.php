<?php
/**
 * Plugin Name: Tutor LMS BunnyNet Integration
 * Description: Integrates Bunny.net video streaming into Tutor LMS.
 * Version: 2.0.0
 * Author: Themeum, Brandon Meyer
 * Text Domain: tutor-lms-bunnynet-integration
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Autoload dependencies if necessary (e.g., Composer).
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Include Bunny.net API handler.
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyApi.php';

// Include Bunny.net settings page.
require_once plugin_dir_path(__FILE__) . 'includes/Admin/BunnySettings.php';

// Include Bunny.net database manager.
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyDatabaseManager.php';

// Include Bunny.net user integration.
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyUserIntegration.php';

/**
 * Initialize the plugin.
 */
function tutor_lms_bunnynet_integration_init() {
    // Initialize Bunny.net settings and API handler.
    $access_key = get_option('bunny_net_access_key', '');
    $library_id = get_option('bunny_net_library_id', '');

    // Initialize BunnySettings to ensure the settings page appears.
    new \Tutor\BunnyNetIntegration\Admin\BunnySettings();

    // Initialize database manager for Bunny.net collections.
    new \Tutor\BunnyNetIntegration\Integration\BunnyDatabaseManager();

    // Initialize user integration for handling instructor collections.
    new \Tutor\BunnyNetIntegration\Integration\BunnyUserIntegration();

    // Global Bunny.net API instance (optional).
    if (!empty($access_key) && !empty($library_id)) {
        $GLOBALS['bunny_net_api'] = new \Tutor\BunnyNetIntegration\Integration\BunnyApi($access_key, $library_id);
    }
}
add_action('plugins_loaded', 'tutor_lms_bunnynet_integration_init');

/**
 * Enqueue admin scripts for Bunny.net integration.
 */
function tutor_lms_bunnynet_enqueue_admin_scripts($hook) {
    // Check if we are on the lesson or course editor page
    if ('post.php' === $hook || 'post-new.php' === $hook) {
        global $post;

        // Ensure this is a Tutor LMS lesson or course post type
        if ($post->post_type === 'tutor_lesson' || $post->post_type === 'tutor_course') {
            wp_enqueue_script(
                'bunny-video-upload',
                plugin_dir_url(__FILE__) . 'assets/js/bunny-video-upload.js',
                ['jquery'], // Add jQuery as a dependency if needed
                '2.0.0',
                true // Load the script in the footer
            );

            // Add localized data for AJAX and other variables
            wp_localize_script('bunny-video-upload', 'bunnyVideoUpload', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('bunny_video_upload_nonce'),
            ]);
        }
    }
}
add_action('admin_enqueue_scripts', 'tutor_lms_bunnynet_enqueue_admin_scripts');
