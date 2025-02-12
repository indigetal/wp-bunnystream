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
require_once plugin_dir_path(__FILE__) . 'includes/Utils/Constants.php';
require_once plugin_dir_path(__FILE__) . 'includes/Admin/BunnySettings.php';
require_once plugin_dir_path(__FILE__) . 'includes/API/BunnyApiClient.php';
require_once plugin_dir_path(__FILE__) . 'includes/API/BunnyApiKeyManager.php';
require_once plugin_dir_path(__FILE__) . 'includes/API/BunnyCollectionHandler.php';
require_once plugin_dir_path(__FILE__) . 'includes/API/BunnyVideoHandler.php';
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyMetadataManager.php';
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyUserIntegration.php';
require_once plugin_dir_path(__FILE__) . 'includes/Integration/BunnyMediaLibrary.php';
require_once plugin_dir_path(__FILE__) . 'includes/Utils/BunnyLogger.php';

// Import the Constants class
use WP_BunnyStream\Utils\Constants;

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
 * Register the block.
 */
function wp_bunnystream_register_block() {
    wp_register_script(
        'bunnystream-block-editor',
        plugins_url('blocks/bunnystream-block.js', __FILE__),
        ['wp-blocks', 'wp-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-block-editor'],
        filemtime(plugin_dir_path(__FILE__) . 'blocks/bunnystream-block.js'),
        true
    );
    
    wp_register_style(
        'bunnystream-block-style',
        plugins_url('blocks/bunny-block.css', __FILE__),
        [],
        filemtime(plugin_dir_path(__FILE__) . 'blocks/bunny-block.css')
    );    

    register_block_type('bunnystream/video', array(
        'editor_script' => 'bunnystream-block-editor',
        'editor_style'  => 'bunnystream-block-style',
        'style'         => 'bunnystream-block-style',
        'render_callback' => 'bunnystream_render_video',
    ));
}
add_action('init', 'wp_bunnystream_register_block', 20);

/**
 * Render callback for the Bunny Stream Video block.
 */
function bunnystream_render_video($attributes) {
    $post_id = get_the_ID();
    $iframe_url = !empty($attributes['iframeUrl']) ? $attributes['iframeUrl'] : get_post_meta($post_id, '_bunny_iframe_url', true);

    // Debugging: Log metadata and attributes
    //$meta = get_post_meta($post_id);
    //error_log("bunnystream_render_video() - Full Post Meta for $post_id: " . print_r($meta, true));
    //error_log("bunnystream_render_video() - Attributes: " . print_r($attributes, true));

    if (empty($iframe_url)) {
        return '<p style="text-align:center; padding:10px; background:#f5f5f5; border-radius:5px; color: red;">
            ' . esc_html__("No video URL found. Please ensure a video is selected.", "bunnystream") . 
            '<br><small>' . esc_html__("Post ID: ", "bunnystream") . esc_html($post_id) . '</small></p>';
    }

    // Construct query parameters from block attributes
    $params = [];
    if (isset($attributes['autoplay']) && $attributes['autoplay']) $params[] = "autoplay=true";
    if (isset($attributes['muted']) && $attributes['muted']) $params[] = "muted=true";
    if (isset($attributes['loop']) && $attributes['loop']) $params[] = "loop=true";
    if (isset($attributes['playsInline']) && $attributes['playsInline']) $params[] = "playsinline=true";
    if (!empty($attributes['captions'])) $params[] = "captions=" . urlencode($attributes['captions']);
    if (isset($attributes['preload']) && $attributes['preload']) $params[] = "preload=" . urlencode($attributes['preload']);
    if (!empty($attributes['t'])) $params[] = "t=" . urlencode($attributes['t']);
    if (isset($attributes['chromecast']) && !$attributes['chromecast']) $params[] = "chromecast=false";
    if (isset($attributes['disableAirplay']) && $attributes['disableAirplay']) $params[] = "disableAirplay=true";
    if (isset($attributes['disableIosPlayer']) && $attributes['disableIosPlayer']) $params[] = "disableIosPlayer=true";
    if (isset($attributes['showHeatmap']) && $attributes['showHeatmap']) $params[] = "showHeatmap=true";
    if (isset($attributes['showSpeed']) && $attributes['showSpeed']) $params[] = "showSpeed=true";

    // Construct the final iframe URL
    $embed_url = esc_url($iframe_url) . (!empty($params) ? '?' . implode("&", $params) : '');

    // Log final embed URL
    //error_log("bunnystream_render_video() - Final Embed URL: " . $embed_url);

    return "<div style='position: relative; padding-top: 56.25%;'>
            <iframe src='{$embed_url}' 
                loading='lazy' 
                style='border: none; position: absolute; top: 0; height: 100%; width: 100%;' 
                allow='accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;' 
                allowfullscreen='true'>
            </iframe>
        </div>";
}

/**
 * Register scheduled event for retrying playback URL retrieval.
 */
add_action('wpbs_retry_fetch_video_url', function($videoId, $postId) {
    \WP_BunnyStream\API\BunnyApiClient::getInstance()->retryFetchVideoPlaybackUrl($videoId, $postId);
}, 10, 2);

function wp_bunnystream_enqueue_frontend_assets() {
    wp_enqueue_style('bunnystream-block-style');
}
add_action('wp_enqueue_scripts', 'wp_bunnystream_enqueue_frontend_assets');

function wp_bunnystream_load_textdomain() {
    load_plugin_textdomain('bunnystream-video', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'wp_bunnystream_load_textdomain');
