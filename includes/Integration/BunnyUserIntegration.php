<?php
namespace WP_BunnyStream\Integration;

use WP_BunnyStream\Integration\BunnyDatabaseManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyUserIntegration {
    private $bunnyApi;

    public function __construct() {
        // Initialize BunnyApi instance
        $this->bunnyApi = \BunnyApiInstance::getInstance();

        // Hook into user actions
        add_action('wp_bunny_video_upload', [$this, 'handleVideoUpload'], 10, 2);
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
     * Handle video upload by instructors.
     * Create a Bunny.net collection for the user if it does not exist.
     *
     * @param int $userId The ID of the user uploading the video.
     * @param string $videoPath The path to the uploaded video.
     */
    public function handleVideoUpload($userId, $videoPath) {
        $dbManager = new BunnyDatabaseManager();
        $collectionId = $dbManager->getUserCollectionId($userId);
    
        if (!$collectionId) {
            // No collection exists for the user, create one.
            $collectionName = "User_Collection_{$userId}";
            $response = $this->bunnyApi->createCollection($collectionName);
    
            if (is_wp_error($response)) {
                error_log('Failed to create collection for user ' . $userId . ': ' . $response->get_error_message());
                return;
            }
    
            $collectionId = $response['id'];
            $dbManager->storeUserCollection($userId, $collectionId);
        }
    
        // Upload video
        $uploadResponse = $this->bunnyApi->uploadVideo($videoPath, $collectionId);
        if (is_wp_error($uploadResponse)) {
            error_log('Video upload failed for user ' . $userId . ': ' . $uploadResponse->get_error_message());
            return;
        }
    
        error_log("Video uploaded by user {$userId} added to collection {$collectionId}.");
    }       

    /**
     * Handle user deletion.
     * Delete the user's Bunny.net collection if it exists.
     *
     * @param int $userId The ID of the user being deleted.
     */
    public function handleUserDeletion($userId) {
        $dbManager = new BunnyDatabaseManager();
        $collectionId = $dbManager->getUserCollectionId($userId);

        if ($collectionId) {
            $response = $this->bunnyApi->deleteCollection($collectionId);
            if (is_wp_error($response)) {
                error_log('Failed to delete collection for user ' . $userId . ': ' . $response->get_error_message());
            } else {
                error_log("Collection {$collectionId} for user {$userId} deleted successfully.");
            }
            
            // Remove collection record from the database
            $dbManager->deleteUserCollection($userId);
        }
    }
}
