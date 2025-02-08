<?php
namespace WP_BunnyStream\Integration;

use WP_BunnyStream\Utils\BunnyLogger;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyMetadataManager {
    /**
     * Store Bunny.net thumbnail URL for a video and set it via API.
     *
     * @param int    $postId       The post ID of the video attachment.
     * @param string $videoId      The Bunny.net video ID.
     * @param string $thumbnailUrl The URL of the Bunny.net-generated thumbnail.
     */
    public function storeBunnyVideoThumbnail($postId, $videoId, $thumbnailUrl) {
        if (empty($postId) || empty($videoId) || empty($thumbnailUrl)) {
            BunnyLogger::log('BunnyMetadataManager: Missing post ID, video ID, or thumbnail URL.');
            return;
        }

        // Store the thumbnail in WordPress metadata
        update_post_meta($postId, '_bunny_thumbnail_url', esc_url($thumbnailUrl));
    }

    /**
     * Store or update video metadata for posts or media library items.
     *
     * @param int   $id        The WordPress post ID or attachment ID.
     * @param array $videoData The video metadata including source, URL, collection ID, GUID, and local path.
     * @return bool True if metadata updated successfully, false otherwise.
     */
    public static function storeVideoMetadata($postId, $metadata) {
        if (!is_numeric($postId) || empty($metadata) || !is_array($metadata)) {
            BunnyLogger::log("storeVideoMetadata: Invalid input parameters.", 'error');
            return;
        }
    
        foreach ($metadata as $key => $value) {
            if (!empty($key) && isset($value)) {
                update_post_meta($postId, '_bunny_' . sanitize_key($key), sanitize_text_field($value));
            }
        }
    
        BunnyLogger::log("storeVideoMetadata: Metadata successfully stored for post ID {$postId}.", 'info');
    }    

    /**
     * Retrieve video metadata for posts or media library items.
     *
     * @param int $id The WordPress post ID or attachment ID.
     * @return array|null The video metadata or null if not found.
     */
    public function getVideoMetadata($id) {
        if (empty($id)) {
            BunnyLogger::log('BunnyMetadataManager: Invalid ID for getVideoMetadata.');
            return null;
        }

        // Ensure ID is either a post or an attachment
        $postType = get_post_type($id);
        if ($postType !== 'post' && $postType !== 'attachment') {
            BunnyLogger::log("BunnyMetadataManager: Invalid post type ({$postType}) for ID {$id}.");
            return null;
        }

        $videoData = get_post_meta($id, '_video', true);
        $videoHandler = \WP_BunnyStream\API\BunnyVideoHandler::getInstance();
        $playbackUrls = $videoHandler->getPlaybackUrls($id);

        $videoData['videoUrl'] = $playbackUrls['mp4'] ?? '';
        $videoData['iframeUrl'] = $playbackUrls['iframe'] ?? '';

        $videoData['thumbnailUrl'] = get_post_meta($id, '_bunny_thumbnail_url', true);

        return array_map('sanitize_text_field', $videoData);
    }

}
