<?php 

namespace WP_BunnyStream\Integration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyApiKeyManager {

    /**
     * BunnyApi instance.
     */
    private $bunnyApi;

    /**
     * Constructor
     */
    public function __construct() {
        $this->bunnyApi = \BunnyApiInstance::getInstance();
    }

    /**
     * Store API keys securely using encryption.
     */
    public static function encrypt_api_key($key) {
        $encryption_key = wp_salt();
        return base64_encode(openssl_encrypt($key, 'aes-256-cbc', $encryption_key, 0, substr($encryption_key, 0, 16)));
    }

    /**
     * Decrypt API keys when retrieving.
     */
    public static function decrypt_api_key($encrypted_key) {
        $encryption_key = wp_salt();
        return openssl_decrypt(base64_decode($encrypted_key), 'aes-256-cbc', $encryption_key, 0, substr($encryption_key, 0, 16));
    }

    /**
     * Save encrypted API key to database.
     */
    public function saveApiKey($key) {
        update_option('bunny_net_access_key', self::encrypt_api_key($key));
    }

    /**
     * Retrieve decrypted API key from database.
     */
    public function getApiKey() {
        $encrypted_key = get_option('bunny_net_access_key', '');
        return self::decrypt_api_key($encrypted_key);
    }

    /**
     * Handle AJAX request to update database settings.
     */
    public function handleDatabaseUpdateAjax() {
        check_ajax_referer('bunny_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'wp-bunnystream')], 403);
        }

        $apiKey = sanitize_text_field($_POST['api_key'] ?? '');

        if (empty($apiKey)) {
            wp_send_json_error(['message' => __('API Key is required.', 'wp-bunnystream')], 400);
        }

        $this->saveApiKey($apiKey);
        wp_send_json_success(['message' => __('API Key updated successfully.', 'wp-bunnystream')]);
    }

}
