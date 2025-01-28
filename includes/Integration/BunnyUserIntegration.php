<?php
namespace Tutor\BunnyNetIntegration\Integration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyUserIntegration {

    public function __construct() {
        // Hook into user actions
        add_action('tutor_video_upload', [$this, 'handleVideoUpload'], 10, 2);
        add_action('delete_user', [$this, 'handleUserDeletion']);
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
            $bunnyApi = $GLOBALS['bunny_net_api'];
            $collectionName = "User_Collection_{$userId}";
            $response = $bunnyApi->createCollection($collectionName);

            if (is_wp_error($response)) {
                error_log('Failed to create collection for user ' . $userId . ': ' . $response->get_error_message());
                return;
            }

            $collectionId = $response['id'];
            $dbManager->storeUserCollection($userId, $collectionId);
        }

        // Associate video with the collection (future implementation)
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
            $bunnyApi = $GLOBALS['bunny_net_api'];
            $response = $bunnyApi->deleteCollection($collectionId);

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
