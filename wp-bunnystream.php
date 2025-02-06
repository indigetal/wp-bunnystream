<?php
/**
 * Plugin Name: WP Bunny Stream
 * Description: Offload and stream videos from Bunny's Stream Service via WordPress Media Library.
 * Version: 1.0
 * Author: Brandon Meyer
 * Text Domain: wp-bunnystream
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Include required files
require_once plugin_dir_path(__FILE__) . 'includes/Admin/BunnySettings.php';
require_once plugin_dir_path(__FILE__) . 'includes/API/BunnyApi.php';
require_once plugin_dir_path(__FILE__) . 'includes/API/BunnyApiKeyManager.php';
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyMetadataManager.php';
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyUserIntegration.php';
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyMediaLibrary.php';
require_once plugin_dir_path(__FILE__) . 'includes/Utils/BunnyLogger.php';
require_once plugin_dir_path(__FILE__) . 'includes/Blocks/BunnyStreamBlock.php';

/**
 * Singleton class for BunnyApi instance.
 */
class BunnyApiInstance {
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = \WP_BunnyStream\API\BunnyApi::getInstance();
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
    new \WP_BunnyStream\API\BunnyApiKeyManager();
    new \WP_BunnyStream\Integration\BunnyMetadataManager();
    new \WP_BunnyStream\Integration\BunnyUserIntegration();
    new \WP_BunnyStream\Integration\BunnyMediaLibrary();
}
add_action('plugins_loaded', 'wp_bunnystream_init');

/**
 * Enqueue admin scripts for Bunny.net integration.
 */
function wp_bunnystream_enqueue_admin_scripts($hook) {
    // Load video upload scripts for Media Library & post editor pages
    if (in_array($hook, ['upload.php', 'post.php', 'post-new.php'])) {
        wp_enqueue_script(
            'bunny-video-upload',
            plugin_dir_url(__FILE__) . 'assets/js/bunny-video-upload.js',
            ['jquery'],
            null,
            true
        );

        // Ensure BunnyApi is initialized
        $bunny_api = WP_BunnyStream\API\BunnyApi::getInstance();

        wp_localize_script('bunny-video-upload', 'bunnyUploadVars', [
            'ajaxurl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('bunny_nonce'),
            'maxFileSize' => WP_BunnyStream\API\BunnyApi::MAX_FILE_SIZE,
        ]);
    }
}
add_action('admin_enqueue_scripts', 'wp_bunnystream_enqueue_admin_scripts');

/**
 * Enqueue scripts for Bunny.net video uploads on the frontend for Tutor LMS integration.
 */
function enqueue_bunny_frontend_scripts() {
    // Ensure the function is available
    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

    // Check if Tutor LMS is active
    if (!is_plugin_active('tutor/tutor.php')) {
        return;
    }

    wp_enqueue_script(
        'bunny-video-upload',
        plugin_dir_url(__FILE__) . 'assets/js/bunny-video-upload.js',
        ['jquery'],
        null,
        true
    );

    // Ensure BunnyApi is initialized
    $bunny_api = WP_BunnyStream\API\BunnyApi::getInstance();

    wp_localize_script('bunny-video-upload', 'bunnyUploadVars', [
        'ajaxurl'     => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('bunny_nonce'),
        'maxFileSize' => WP_BunnyStream\API\BunnyApi::MAX_FILE_SIZE,
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_bunny_frontend_scripts');

/**
 * Register scheduled event for retrying playback URL retrieval.
 */
add_action('wpbs_retry_fetch_video_url', function($videoId, $postId) {
    BunnyApiInstance::getInstance()->retryFetchVideoPlaybackUrl($videoId, $postId);
}, 10, 2);
