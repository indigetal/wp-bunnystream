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

        // Check if user already has a Bunny.net collection
        $collection_id = $this->databaseManager->getUserCollectionId($user_id);
        if (!$collection_id) {
            $collectionName = "User_Collection_{$user_id}";
            $response = $this->bunnyApi->getCollection($collection_id);
            if (is_wp_error($response) || empty($response)) {
                $response = $this->bunnyApi->createCollection($collectionName);
                if (is_wp_error($response)) {
                    error_log('Failed to create Bunny.net collection: ' . $response->get_error_message());
                    return $upload;
                }
                $collection_id = $response['id'];
                $this->databaseManager->storeUserCollection($user_id, $collection_id);
            }
        }

        // Upload video to Bunny.net
        $uploadResponse = $this->bunnyApi->uploadVideo($upload['file'], $collection_id);
        if (is_wp_error($uploadResponse)) {
            error_log('Bunny.net Video Upload Failed: ' . $uploadResponse->get_error_message());
            return $upload;
        }

        // Store Bunny.net metadata
        $bunny_video_id = $uploadResponse['videoId'];
        $bunny_video_url = $uploadResponse['videoUrl'] ?? '';

        $upload['url'] = $bunny_video_url; // Replace WordPress URL with Bunny.net URL
        $upload['file'] = ''; // Prevent local file storage
        $upload['bunny_video_id'] = $bunny_video_id;
        
        return $upload;
    }

    /**
     * Store Bunny.net metadata when an attachment is added
     */
    public function handleAttachmentMetadata($post_id) {
        $bunny_video_url = get_post_meta($post_id, '_bunny_video_url', true);
        if ($bunny_video_url) {
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

        $this->metadataManager->updatePostVideoMetadata($post_id, [
            'source' => 'bunnycdn',
            'source_bunnynet' => $bunny_video_url['playbackUrl'],
            'video_id' => $bunny_video_id,
        ]);
    }
}

// Initialize the media library integration
new BunnyMediaLibrary();