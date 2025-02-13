<?php

namespace WP_BunnyStream\API;

use WP_BunnyStream\Admin\BunnySettings;
use WP_BunnyStream\Utils\BunnyLogger;
use WP_BunnyStream\API\BunnyApiClient;

class BunnyVideoHandler {
    private static $instance = null;
    private $apiClient;
    private $collectionHandler;
    public $video_base_url;
    private $access_key;

    private function __construct() {
        $this->apiClient = BunnyApiClient::getInstance();
        $this->collectionHandler = BunnyCollectionHandler::getInstance();
        $this->video_base_url = $this->apiClient->video_base_url;
        $this->access_key = $this->apiClient->getAccessKey();
    }    

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPlaybackUrls($postId) {
        $videoId = get_post_meta($postId, '_bunny_video_id', true);
        if (empty($videoId)) {
            return null;
        }
    
        $pullZone = get_option('bunny_net_pull_zone', '');
        $iframeUrl = get_post_meta($postId, '_bunny_iframe_url', true); // Use stored value
    
        return [
            'mp4'    => "https://{$pullZone}/{$videoId}/play_720p.mp4",
            'iframe' => $iframeUrl, // No need to reconstruct dynamically
        ];
    }    

    /**
     * Upload a video to Bunny.net.
     * 
     * @param string $filePath The path to the video file on the server.
     * @param string|null $collectionId The collection ID to associate the video with (optional).
     * @param int|null $postId The post ID (optional).
     * @param int|null $userId The user ID (optional).
     * @return array|WP_Error The API response or WP_Error on failure.
     */
    public function uploadVideo($filePath, $collectionId = null, $postId = null, $userId = null) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to upload a video.', 'wp-bunnystream'));
        }

        if (!is_string($filePath) || !file_exists($filePath)) {
            return new \WP_Error('invalid_file_path', __('Invalid file path for video upload.', 'wp-bunnystream'));
        }

        // Step 1: Ensure a valid collection ID exists before uploading
        if ($userId) {
            $collectionId = get_user_meta($userId, '_bunny_collection_id', true);

            // Validate if the collection exists on Bunny.net
            if (!empty($collectionId)) {
                $collectionCheck = $this->collectionHandler->getCollection($collectionId);

                if ($collectionCheck === null || is_wp_error($collectionCheck)) {
                    BunnyLogger::log("Stored collection ID {$collectionId} not found on Bunny.net. Removing and creating a new one.", 'error');

                    // Remove stale collection from user meta
                    delete_user_meta($userId, '_bunny_collection_id');
                    $collectionId = null; // Reset collectionId for re-creation
                }
            }

            // If the collection is still null, create a new one
            if (empty($collectionId)) {
                $collectionId = $this->collectionHandler->createCollection($userId);

                // Validate new collection creation
                if (!$collectionId || is_wp_error($collectionId)) {
                    return new \WP_Error('collection_creation_failed', __('Collection creation failed, video upload aborted.', 'wp-bunnystream'));
                }

                // Store new collection ID
                update_user_meta($userId, '_bunny_collection_id', $collectionId);
            }
        }

        // Step 2: Create a new video object in the collection
        $videoId = $this->createVideoObject(basename($filePath), $collectionId);

        if (is_wp_error($videoId)) {
            BunnyLogger::log("uploadVideo: Failed to create video object. Error: " . $videoId->get_error_message(), 'error');
            return $videoId;
        }

        BunnyLogger::log("uploadVideo: Created video ID {$videoId}. Uploading file to Bunny.net.", 'debug');

        // Step 3: Upload the video file using a PUT request
        if (empty($library_id) || empty($videoId)) {
            BunnyLogger::log("uploadVideo: ERROR - Missing Library ID or Video ID. Library ID: {$library_id}, Video ID: {$videoId}", 'error');
            return new \WP_Error('missing_video_data', __('Missing library ID or video ID.', 'wp-bunnystream'));
        }
        
        $uploadEndpoint = "library/{$library_id}/videos/{$videoId}";
        
        $videoData = file_get_contents($filePath);
        if ($videoData === false || strlen($videoData) === 0) {
            BunnyLogger::log("uploadVideo: Failed to read video file for {$filePath}.", 'error');
            return new \WP_Error('video_file_read_failed', __('Failed to read the video file before uploading.', 'wp-bunnystream'));
        }

        $uploadResponse = $this->apiClient->executeWithRetry(function() use ($uploadEndpoint, $videoData) {
            return wp_remote_request($this->video_base_url . $uploadEndpoint, [
                'method'    => 'PUT',
                'headers'   => [
                    'AccessKey'    => $this->apiClient->getAccessKey(),
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/octet-stream'
                ],
                'body'      => $videoData,
                'timeout'   => 300,
            ]);
        });

        if (is_wp_error($uploadResponse)) {
            BunnyLogger::log("uploadVideo: File upload failed for {$filePath}. Error: " . $uploadResponse->get_error_message(), 'error');
            return new \WP_Error('video_upload_failed', __('Failed to upload video file to Bunny.net.', 'wp-bunnystream'));
        }

        // Log the full response from Bunny.net
        $responseBody = wp_remote_retrieve_body($uploadResponse);
        BunnyLogger::log("uploadVideo: Bunny.net Response - " . print_r($responseBody, true), 'debug');

        // Fetch the stored Pull Zone from the settings
        $pullZone = get_option('bunny_net_pull_zone', '');
        if (empty($pullZone)) {
            BunnyLogger::log('Pull Zone is missing or not set. Using default Bunny.net CDN.', 'warning');
            $pullZone = "video.bunnycdn.com"; // Default Bunny.net CDN if pull zone isn't set
        }

        if ($postId) {
            $library_id = $this->apiClient->getLibraryId();
            if (!empty($library_id)) {
                update_post_meta($postId, '_bunny_iframe_url', "https://iframe.mediadelivery.net/embed/{$library_id}/{$videoId}");
            }
        
            update_post_meta($postId, '_bunny_video_id', $videoId);
            update_post_meta($postId, '_bunny_playback_mode', 'mp4'); // Default to MP4
        }        

        $pullZone = get_option('bunny_net_pull_zone', '');
        $playbackUrl = "https://{$pullZone}/{$videoId}/play_720p.mp4"; // Default 720p resolution

        return [
            'videoId'   => $videoId,
            'videoUrl'  => $playbackUrl, // Dynamically constructed MP4 URL
            'iframeUrl' => get_post_meta($postId, '_bunny_iframe_url', true), // Retrieve stored iframe URL
        ];        

    }
    
    /**
    * Create a video object
    */
    public function createVideoObject($title, $collectionId) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to create a video object.', 'wp-bunnystream'));
        }
    
        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required to create a video object.', 'wp-bunnystream'));
        }
    
        $videoData = [
            'title' => $title,
            'collectionId' => trim($collectionId), // No need for an if check; collectionId is always required
        ];
    
        $response = $this->apiClient->sendJsonToBunny("library/{$library_id}/videos", 'POST', $videoData);
    
        if (is_string($response)) {
            BunnyLogger::log("createVideoObject: Response was a string, decoding it now.", 'warning');
            $response = json_decode($response, true);
        }
    
        if (is_wp_error($response) || empty($response['guid'])) {
            return new \WP_Error('video_creation_failed', __('Failed to create video object.', 'wp-bunnystream'));
        }
        
        return $response['guid'];        
    }
    
    /**
     * Set a thumbnail for a video in Bunny.net.
     *
     * @param string $videoId The ID of the video.
     * @param int|null $timestamp (Optional) The timestamp (in seconds) from which to generate the thumbnail.
     * @param int|null $postId (Optional) The post ID where the thumbnail should be stored.
     * @return bool|WP_Error True on success, WP_Error on failure.
    */
    public function setThumbnail($videoId, $timestamp = null, $postId = null) {
        $library_id = $this->apiClient->getLibraryId();
        if (empty($library_id)) {
            BunnyLogger::log('Library ID is missing or not set.', 'warning');
            return new \WP_Error('missing_library_id', __('Library ID is required to set a thumbnail.', 'wp-bunnystream'));
        }

        if (empty($videoId)) {
            return new \WP_Error('missing_video_id', __('Video ID is required to set a thumbnail.', 'wp-bunnystream'));
        }

        $pullZone = get_option('bunny_net_pull_zone', '');
        if (empty($pullZone)) {
            BunnyLogger::log('Pull Zone is missing or not set.', 'warning');
            return new \WP_Error('missing_pull_zone', __('Pull Zone is required to set a thumbnail.', 'wp-bunnystream'));
        }
        // Construct the static thumbnail URL
        $thumbnailUrl = "https://{$pullZone}/{$videoId}/thumbnail.jpg";

        // Store thumbnail URL in post meta
        if ($postId) {
            update_post_meta($postId, '_bunny_thumbnail_url', $thumbnailUrl);
            BunnyLogger::log("setThumbnail: Stored thumbnail URL in post meta for post ID: {$postId}", 'info');
        }

        // If a timestamp is provided, make a request to update the thumbnail
        if (!is_null($timestamp)) {
            $endpoint = "library/{$library_id}/videos/{$videoId}/thumbnail";
            $data = ['time' => $timestamp];

            $response = $this->apiClient->sendJsonToBunny($endpoint, 'POST', $data);

            if (is_wp_error($response)) {
                BunnyLogger::log('Bunny API Error: Failed to set video thumbnail: ' . $response->get_error_message(), 'error');
                return $response;
            }
        }

        return true;
    }

    /**
     * Validate MIME type before file upload.
     */
    public function validateMimeType($filePath) {
        $mime_type = mime_content_type($filePath);
        if (!in_array($mime_type, ['video/mp4', 'video/webm'])) {
            return new \WP_Error('invalid_mime', __('Invalid file type.', 'wp-bunnystream'));
        }
        return true;
    }

    /**
     * Deletes a video from Bunny.net's video library.
     *
     * @param string $library_id The Bunny.net library ID.
     * @param string $video_id   The Bunny.net video ID to be deleted.
     * @return bool True on success, false on failure.
     */
    public function deleteVideo($library_id, $video_id) {
        // Validate input parameters
        if (empty($library_id) || empty($video_id)) {
            BunnyLogger::log("deleteVideo: Missing library ID or video ID. Library ID: {$library_id}, Video ID: {$video_id}", 'error');
            return false;
        }

        // Construct the API endpoint
        $deleteEndpoint = "library/{$library_id}/videos/{$video_id}";

        // Execute the DELETE request using sendJsonToBunny
        $response = $this->apiClient->sendJsonToBunny($deleteEndpoint, 'DELETE');

        // Handle response errors
        if (is_wp_error($response)) {
            BunnyLogger::log("deleteVideo: API request failed. Error: " . $response->get_error_message(), 'error');
            return false;
        }

        // Ensure response is an array and contains expected fields
        if (!is_array($response) || !isset($response['success'], $response['statusCode'])) {
            BunnyLogger::log("deleteVideo: Unexpected response structure from Bunny.net. Response: " . json_encode($response), 'error');
            return false;
        }

        // Extract statusCode from the response body
        $statusCode = (int) $response['statusCode'];
        $responseBody = json_encode($response);

        // Handle responses based on Bunny.net API documentation
        switch ($statusCode) {
            case 200:
                BunnyLogger::log("deleteVideo: Successfully deleted video ID {$video_id} from library {$library_id}. Response: {$responseBody}", 'info');
                return true;
            case 401:
                BunnyLogger::log("deleteVideo: Authorization failed. Check your Bunny.net Access Key.", 'error');
                return false;
            case 404:
                BunnyLogger::log("deleteVideo: Video ID {$video_id} not found in library {$library_id}. It may have already been deleted.", 'warning');
                return true; // No need to retry, since it's already gone.
            case 500:
                BunnyLogger::log("deleteVideo: Internal server error at Bunny.net. Retry may be required.", 'error');
                return false;
            default:
                BunnyLogger::log("deleteVideo: Unexpected response from Bunny.net. Status Code: {$statusCode}, Response: {$responseBody}", 'error');
                return false;
        }
    }
}