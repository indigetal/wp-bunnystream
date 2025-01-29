<?php
/**
 * Plugin Name: WP Bunny Stream
 * Description: Offload and stream videos from Bunny's Stream Service via WordPress Media Library.
 * Version: 0.1.0
 * Author: Brandon Meyer
 * Text Domain: wp-bunnystream
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include required files
require_once plugin_dir_path(__FILE__) . 'includes/Admin/BunnySettings.php';
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyApi.php';
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyDatabaseManager.php';
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyUserIntegration.php';

/**
 * Singleton class for BunnyApi instance.
 */
class BunnyApiInstance {
    public static function getInstance() {
        return \WP_BunnyStream\Integration\BunnyApi::getInstance();
    }
}

/**
 * Initialize the plugin.
 */
function wp_bunnystream_init() {
    // Initialize settings and database management
    new \WP_BunnyStream\Admin\BunnySettings();
    new \WP_BunnyStream\Integration\BunnyDatabaseManager();
    new \WP_BunnyStream\Integration\BunnyUserIntegration();
}
add_action('plugins_loaded', 'wp_bunnystream_init');

/**
 * Enqueue admin scripts for Bunny.net integration.
 */
function wp_bunnystream_enqueue_admin_scripts($hook) {
    if ('upload.php' === $hook || 'post.php' === $hook || 'post-new.php' === $hook) {
        wp_enqueue_script(
            'bunny-video-upload',
            plugin_dir_url(__FILE__) . 'assets/js/bunny-video-upload.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('bunny-video-upload', 'bunnyVideoUpload', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bunny_video_upload_nonce'),
        ]);
    }
}
add_action('admin_enqueue_scripts', 'wp_bunnystream_enqueue_admin_scripts');
