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
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = \WP_BunnyStream\Integration\BunnyApi::getInstance();
        }
        return self::$instance;
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

        wp_localize_script('bunny-video-upload', 'bunnyUploadVars', [
    'ajaxurl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('bunny_nonce'), // Match check_ajax_referer()
]);
    }
}
add_action('admin_enqueue_scripts', 'wp_bunnystream_enqueue_admin_scripts');

/**
 * Enqueue frontend scripts for Bunny.net video uploads.
 */
function enqueue_bunny_frontend_scripts() {
    if (is_admin()) {
        return; // Don't load in WP Admin
    }

    wp_enqueue_script(
        'bunny-frontend-script',
        plugin_dir_url(__FILE__) . 'assets/js/bunny-frontend.js',
        ['jquery'],
        null,
        true
    );

    wp_localize_script('bunny-frontend-script', 'bunnyUploadVars', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('bunny_nonce'),
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_bunny_frontend_scripts');
