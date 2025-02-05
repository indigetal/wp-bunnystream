<?php
namespace WP_BunnyStream\Integration;

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
            error_log('BunnyMetadataManager: Missing post ID, video ID, or thumbnail URL.');
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
    public function storeVideoMetadata($id, $videoData) {
        if (empty($id) || empty($videoData)) {
            error_log('BunnyMetadataManager: Invalid parameters for storeVideoMetadata.');
            return false;
        }

        // Ensure ID is either a post or an attachment
        $postType = get_post_type($id);
        if ($postType !== 'post' && $postType !== 'attachment') {
            error_log("BunnyMetadataManager: Invalid post type ({$postType}) for ID {$id}.");
            return false;
        }

        // Validate required keys
        if (!isset($videoData['source']) || !isset($videoData['videoId'])) {
            error_log('BunnyMetadataManager: Missing video source or video ID.');
            return false;
        }

        // Sanitize input
        $videoData = array_map('sanitize_text_field', $videoData);
        
        // Store video metadata in `_video` meta key (excluding videoUrl & thumbnailUrl)
        unset($videoData['videoUrl'], $videoData['thumbnailUrl']);
        update_post_meta($id, '_video', $videoData);
        
        // Store playback URL separately
        if (!empty($videoData['videoId'])) {
            $playbackUrl = "https://iframe.mediadelivery.net/play/" . get_option('bunny_library_id') . "/" . $videoData['videoId'];
            update_post_meta($id, '_bunny_video_url', esc_url($playbackUrl));
        }

        return true;
    }

    /**
     * Retrieve video metadata for posts or media library items.
     *
     * @param int $id The WordPress post ID or attachment ID.
     * @return array|null The video metadata or null if not found.
     */
    public function getVideoMetadata($id) {
        if (empty($id)) {
            error_log('BunnyMetadataManager: Invalid ID for getVideoMetadata.');
            return null;
        }

        // Ensure ID is either a post or an attachment
        $postType = get_post_type($id);
        if ($postType !== 'post' && $postType !== 'attachment') {
            error_log("BunnyMetadataManager: Invalid post type ({$postType}) for ID {$id}.");
            return null;
        }

        $videoData = get_post_meta($id, '_video', true);
        $videoData['videoUrl'] = get_post_meta($id, '_bunny_video_url', true);
        $videoData['thumbnailUrl'] = get_post_meta($id, '_bunny_thumbnail_url', true);

        return array_map('sanitize_text_field', $videoData);
    }

    /**
     * Override wp_get_attachment_metadata to use Bunny.net thumbnails if available.
     *
     * @param array $metadata The existing attachment metadata.
     * @param int   $postId   The attachment post ID.
     * @return array The modified metadata with Bunny.net thumbnail.
     */
    public function filterBunnyVideoThumbnail($metadata, $postId) {
        $bunnyThumbnailUrl = get_post_meta($postId, '_bunny_thumbnail_url', true);
        if (!empty($bunnyThumbnailUrl)) {
            $metadata['bunny_thumbnail'] = esc_url($bunnyThumbnailUrl);
        }
        return $metadata;
    }

    /**
     * Override wp_get_attachment_url to use Bunny.net video URL if available.
     *
     * @param string $url The original attachment URL.
     * @param int    $postId The attachment post ID.
     * @return string The overridden Bunny.net URL if available, otherwise the original URL.
     */
    public function filterBunnyVideoURL($url, $postId) {
        $bunnyVideoURL = get_post_meta($postId, '_bunny_video_url', true);
        return !empty($bunnyVideoURL) ? esc_url($bunnyVideoURL) : $url;
    }
}

// Apply the filter to override media library URLs
add_filter('wp_get_attachment_url', [new BunnyMetadataManager(), 'filterBunnyVideoURL'], 10, 2);

// Apply the filter to override video thumbnails in the Media Library
add_filter('wp_get_attachment_metadata', [new BunnyMetadataManager(), 'filterBunnyVideoThumbnail'], 10, 2);
