<?php
namespace WP_BunnyStream\Integration;

use WP_BunnyStream\Integration\BunnyApi;
use WP_BunnyStream\Integration\BunnyMetadataManager;
use WP_BunnyStream\Integration\BunnyDatabaseManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyMediaLibrary {
    private $bunnyApi;
    private $metadataManager;
    private $databaseManager;

    public function __construct() {
        $this->bunnyApi = BunnyApi::getInstance();
        $this->metadataManager = new BunnyMetadataManager();
        $this->databaseManager = new BunnyDatabaseManager();

        add_filter('wp_handle_upload', [$this, 'interceptUpload'], 10, 2);
        add_action('add_attachment', [$this, 'handleAttachmentMetadata'], 10, 1);
    }

    /**
     * Intercept video uploads and offload them to Bunny.net
     */
    public function interceptUpload($upload, $context) {
        if (!isset($upload['type']) || strpos($upload['type'], 'video/') !== 0) {
            return $upload; // Not a video file, proceed normally
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return $upload; // User must be logged in to upload
        }

        if (empty($user_id)) {
            error_log("Bunny API Error: Attempted to create a collection with an empty user ID.");
            return new \WP_Error('missing_user_id', __('User ID is required to create a collection.', 'wp-bunnystream'));
        }

        // Check if user already has a Bunny.net collection
        $collection_id = $this->databaseManager->getUserCollectionId($user_id);
        if (!$collection_id) {
            $user = get_userdata($user_id);
            $username = $user ? sanitize_title($user->user_login) : "user_{$user_id}";
            $collectionName = "wpbs_{$username}";

            // Check if collection already exists before creating a new one
            $existingCollection = $this->databaseManager->getCollectionByName($collectionName);
            if ($existingCollection) {
                // Verify that the collection actually exists on Bunny.net
                $apiCheck = $this->bunnyApi->getCollection($existingCollection);
                if (!is_wp_error($apiCheck)) {
                    $collection_id = $existingCollection; // Use database value if Bunny.net confirms its existence
                } else {
                    error_log("Bunny API Warning: Local database references a collection that does not exist on Bunny.net. Recreating...");
                }
            }            

            if (!$collection_id || is_wp_error($apiCheck)) {
                $response = $this->bunnyApi->createCollection($collectionName, [], $user_id);            
                error_log('Bunny API Collection Creation Response: ' . print_r($response, true));

                if (is_wp_error($response)) {
                    error_log('Failed to create Bunny.net collection: ' . $response->get_error_message());
                    return $upload;
                }

                if (!isset($response['guid'])) {
                    error_log('Failed to create Bunny.net collection: API response did not include a GUID.');
                    return $upload;
                }

                $collection_id = $response['guid'];
                $this->databaseManager->storeUserCollection($user_id, $collection_id);
            }
        }

        // Upload video to Bunny.net
        $uploadResponse = $this->bunnyApi->uploadVideo($upload['file'], $collection_id);
        if (is_wp_error($uploadResponse)) {
            error_log('Bunny.net Video Upload Failed: ' . $uploadResponse->get_error_message());
            return $upload;
        } else {
            error_log('Bunny.net Video Upload Success: ' . print_r($uploadResponse, true));
        }

        // Store Bunny.net metadata
        $bunny_video_id = $uploadResponse['videoId'];
        $bunny_video_url = $uploadResponse['videoUrl'] ?? '';

        // Store metadata using the updated method
        $this->metadataManager->storeVideoMetadata($upload['post_id'], [
            'source' => 'bunnycdn',
            'videoUrl' => $bunny_video_url,
            'collectionId' => $collection_id,
            'videoGuid' => $bunny_video_id,
        ]);

        $upload['url'] = $bunny_video_url; // Replace WordPress URL with Bunny.net URL
        $upload['file'] = ''; // Prevent local file storage
        $upload['bunny_video_id'] = $bunny_video_id;
        
        return $upload;
    }

    /**
     * Store Bunny.net metadata when an attachment is added
     */
    public function handleAttachmentMetadata($post_id) {
        $videoMetadata = $this->metadataManager->getVideoMetadata($post_id);
        if (!empty($videoMetadata['videoUrl'])) {
            return; // Already processed
        }

        $bunny_video_id = get_post_meta($post_id, '_bunny_video_id', true);
        if (!$bunny_video_id) {
            return;
        }

        // Fetch video URL if not already stored
        $bunny_video_url = $this->bunnyApi->getVideoPlaybackUrl($bunny_video_id);
        if (is_wp_error($bunny_video_url) || empty($bunny_video_url['playbackUrl'])) {
            error_log("Warning: Playback URL not found for Video ID {$bunny_video_id}");
            return;
        }

        $this->metadataManager->storeVideoMetadata($post_id, [
            'source' => 'bunnycdn',
            'videoUrl' => $bunny_video_url['playbackUrl'],
            'videoGuid' => $bunny_video_id,
        ]);
    }
}

// Initialize the media library integration
new BunnyMediaLibrary();
