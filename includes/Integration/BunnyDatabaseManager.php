<?php 

namespace WP_BunnyStream\Integration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyDatabaseManager {

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

    /**
     * Create the collections table for storing user-collection associations.
     * Supports multisite environments.
     */
    public static function createCollectionsTable() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bunny_collections';
    
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            collection_id VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";
    
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }    

    /**
     * Retrieve the collection ID associated with a user.
     */
    public function getUserCollectionId($userId) {
        return get_user_meta($userId, '_bunny_collection_id', true);
    }            

    /**
     * Store a user-to-collection association.
     */
    public function storeUserCollection($userId, $collectionId) {
        if (empty($userId) || empty($collectionId)) {
            return new \WP_Error('missing_parameters', __('User ID and Collection ID are required.', 'wp-bunnystream'));
        }
    
        // Check if a collection already exists
        $existingCollection = get_user_meta($userId, '_bunny_collection_id', true);
    
        if ($existingCollection === $collectionId) {
            return true; // No update needed, already set
        }
    
        // Store or update the collection in User Meta
        update_user_meta($userId, '_bunny_collection_id', $collectionId);
    
        return true;
    }        

    public function getCollectionById($collectionId, $networkWide = false) {
        global $wpdb;
    
        $table_name = $networkWide && is_multisite()
            ? $wpdb->base_prefix . 'bunny_collections'
            : $wpdb->prefix . 'bunny_collections';
    
        $collection_id = $wpdb->get_var($wpdb->prepare(
            "SELECT collection_id FROM $table_name WHERE collection_id = %s LIMIT 1",
            $collectionId
        ));
    
        if (!$collection_id) {
            error_log("BunnyDatabaseManager: No collection found for ID {$collectionId}.");
        }
    
        return $collection_id ?: null;
    }        

    /**
     * Delete the collection record associated with a user.
     */
    public function deleteUserCollection($userId, $networkWide = false) {
        global $wpdb;

        $table_name = $networkWide && is_multisite()
            ? $wpdb->base_prefix . 'bunny_collections'
            : $wpdb->prefix . 'bunny_collections';

        $deleted = $wpdb->delete(
            $table_name,
            ['user_id' => $userId],
            ['%d']
        );

        if ($deleted) {
            error_log("BunnyDatabaseManager: Deleted collection record for user ID {$userId}.");
        } else {
            error_log("BunnyDatabaseManager: No collection found to delete for user ID {$userId}.");
        }
    }

}
