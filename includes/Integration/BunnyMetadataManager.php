<?php
namespace WP_BunnyStream\Integration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnyMetadataManager {
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
        if (!isset($videoData['source'])) {
            error_log('BunnyMetadataManager: Missing video source.');
            return false;
        }

        // Sanitize input
        $videoData = array_map('sanitize_text_field', $videoData);
        if (isset($videoData['videoUrl'])) {
            $videoData['videoUrl'] = esc_url($videoData['videoUrl']);
        }

        // Store metadata under `_video`
        $result = update_post_meta($id, '_video', $videoData);

        if (!$result) {
            error_log("BunnyMetadataManager: Failed to store metadata for ID {$id}.");
            return false;
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

        if (empty($videoData)) {
            error_log("BunnyMetadataManager: No video metadata found for ID {$id}.");
            return null;
        }

        return array_map('sanitize_text_field', $videoData);
    }

    /**
     * Deletes the video metadata for a specific post or media library item.
     *
     * @param int $id The post ID or attachment ID.
     * @return bool True if metadata deleted successfully, false otherwise.
     */
    public function deleteVideoMetadata($id) {
        if (empty($id)) {
            error_log('BunnyMetadataManager: Invalid ID for deleteVideoMetadata.');
            return false;
        }

        // Ensure ID is either a post or an attachment
        $postType = get_post_type($id);
        if ($postType !== 'post' && $postType !== 'attachment') {
            error_log("BunnyMetadataManager: Invalid post type ({$postType}) for ID {$id}.");
            return false;
        }

        $result = delete_post_meta($id, '_video');

        if (!$result) {
            error_log("BunnyMetadataManager: Failed to delete metadata for ID {$id}.");
            return false;
        }

        return true;
    }
}
