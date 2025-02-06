<?php
namespace WP_BunnyStream\Integration;

use WP_BunnyStream\API\BunnyApiKeyManager;
use WP_BunnyStream\Integration\BunnyMetadataManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyUserIntegration {
    private $bunnyApi;
    private $metadataManager;
    private $databaseManager;

    public function __construct() {
        // Initialize BunnyApi instance
        $this->bunnyApi = \BunnyApiInstance::getInstance();
        $this->metadataManager = new BunnyMetadataManager();
        $this->databaseManager = new BunnyApiKeyManager();

        // Hook into user actions
        add_action('delete_user', [$this, 'handleUserDeletion']);
    }

    /**
     * Validate and process WordPress request for video uploads.
     *
     * @param array $request The $_POST and $_FILES data for the upload.
     *
     * @return array|\WP_Error Processed request data or error.
     */
    public function validateUploadRequest($request) {
        // Verify user permissions.
        if (!current_user_can('upload_files')) {
            return new \WP_Error('unauthorized_access', __('Unauthorized access.', 'wp-bunnystream'));
        }

        // Check nonce for security
        if (!isset($request['_wpnonce']) || !wp_verify_nonce($request['_wpnonce'], 'bunny_upload_nonce')) {
            return new \WP_Error('invalid_nonce', __('Invalid nonce.', 'wp-bunnystream'));
        }

        // Check for uploaded file and post ID.
        if (empty($request['file']) || empty($request['post_id'])) {
            return new \WP_Error('missing_parameters', __('Missing file or post ID.', 'wp-bunnystream'));
        }

        // Sanitize post ID.
        $postId = (int) sanitize_text_field($request['post_id']);
        return [
            'file' => $request['file'],
            'post_id' => $postId,
        ];
    }
    
    /**
     * Handle user deletion.
     * Delete the user's Bunny.net collection if it exists.
     *
     * @param int $userId The ID of the user being deleted.
     */
    public function handleUserDeletion($userId) {
        $user = get_userdata($userId);
        $username = $user ? sanitize_title($user->user_login) : "user_{$userId}";
        $collectionName = "wpbs_{$username}";
        $collectionId = $this->databaseManager->getCollectionByName($collectionName);

        if ($collectionId) {
            $response = $this->bunnyApi->deleteCollection($collectionId);
            if (is_wp_error($response)) {
                error_log('Failed to delete collection for user ' . $userId . ': ' . $response->get_error_message());
            } else {
                error_log("Collection {$collectionId} for user {$userId} deleted successfully.");
            }
            
            // Remove collection record from the database
            delete_user_meta($userId, '_bunny_collection_id');
        }
    }
}
