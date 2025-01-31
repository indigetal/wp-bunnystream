<?php

namespace WP_BunnyStream\Integration;

use WP_BunnyStream\Admin\BunnySettings;

class BunnyApi {
    private static $instance = null;
    private $video_base_url = 'https://video.bunnycdn.com/';
    private $library_base_url = 'https://api.bunny.net/';
    private $access_key;
    private $library_id;

    const MAX_FILE_SIZE = 500 * 1024 * 1024; // 500MB limit

    private function __construct() {
        $this->access_key = BunnySettings::decrypt_api_key(get_option('bunny_net_access_key', ''));
        $this->library_id = BunnySettings::decrypt_api_key(get_option('bunny_net_library_id', ''));
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self(); // Correct way to call private constructor
        }
        return self::$instance;
    }

    private function getLibraryId() {
        if (empty($this->library_id)) {
            $this->library_id = BunnySettings::decrypt_api_key(get_option('bunny_net_library_id', ''));
            if (empty($this->library_id)) {
                error_log('Bunny API Warning: Library ID is missing or not set.');
            }
        }
        return $this->library_id;
    }    

    /**
     * Generic method to send JSON requests to Bunny.net with retry logic.
     */
    private function sendJsonToBunny($endpoint, $method, $data = [], $useLibraryBase = false) {
        $base_url = $useLibraryBase ? $this->library_base_url : $this->video_base_url;
        $url = $base_url . ltrim($endpoint, '/');

        // Validate HTTP method
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            return new \WP_Error('invalid_http_method', __('Invalid HTTP method provided.', 'wp-bunnystream'));
        }

        // Prepare headers
        $headers = [
            'AccessKey' => $this->access_key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // Build request arguments
        $args = [
            'method' => $method,
            'headers' => $headers,
        ];

        // Add body if not a GET request
        if ($method !== 'GET' && !empty($data)) {
            $args['body'] = json_encode($data);
        }

        return $this->retryApiCall(function() use ($url, $args, $endpoint) {
            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                return $response;
            }
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code < 200 || $response_code >= 300) {
                error_log("Bunny API Error: Failed Request to $endpoint");
                error_log("Headers: " . print_r($headers, true));
            
                if ($method !== 'GET') { // Log request body for POST, PUT, DELETE
                    error_log("Request Body: " . json_encode($data));
                }
            }                                    
            return json_decode($response_body, true);
        });
    }

    /**
     * Retry failed API calls with exponential backoff.
     */
    private function retryApiCall($callback, $maxAttempts = 3) {
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $response = $callback();
            if (!is_wp_error($response)) {
                return $response;
            }
            sleep(pow(2, $attempt)); // Exponential backoff
            $attempt++;
        }
        return new \WP_Error('api_failure', __('Bunny.net API failed after multiple attempts.', 'wp-bunnystream'));
    }

    /**
     * Create a new collection within a library.
     *
     * @param string $collectionName The name of the collection.
     * @param array $additionalData (Optional) Additional data for the collection, like a description.
     * @param int|null $userId (Optional) The user ID for associating the collection in the database.
     * @return array|WP_Error The created collection data or WP_Error on failure.
     */
    public function createCollection($collectionName, $additionalData = [], $userId = null) {
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to create a collection.', 'wp-bunnystream'));
        }
    
        if (empty($collectionName)) {
            return new \WP_Error('missing_collection_name', __('Collection name is required.', 'wp-bunnystream'));
        }
    
        // Since we know the collection name format, construct the collection ID and check if it exists
        $collectionId = "wpbs_uid_{$userId}";
        $existingCollection = $this->getCollection($collectionId);
    
        if (!is_wp_error($existingCollection) && isset($existingCollection['id'])) {
            return $existingCollection; // Return existing collection if found
        }
    
        // Collection does not exist, so create it
        $endpoint = "library/{$library_id}/collections";
        $data = array_merge(['name' => $collectionName], $additionalData);
        $response = $this->sendJsonToBunny($endpoint, 'POST', $data);
    
        if (is_wp_error($response) || empty($response['id'])) {
            return new \WP_Error('collection_creation_failed', __('Failed to create or retrieve collection.', 'wp-bunnystream'));
        }
    
        if ($userId) {
            $dbManager = new \WP_BunnyStream\Integration\BunnyDatabaseManager();
            $dbManager->storeUserCollection($userId, $response['id']);
        }
    
        return $response;
    }    

    /**
     * Create a new video object in Bunny.net.
     */
    public function createVideoObject($title) {
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is not set in the plugin settings.', 'wp-bunnystream'));
        }
        if (empty($title)) {
            return new \WP_Error('missing_video_title', __('Video title is required.', 'wp-bunnystream'));
        }

        $endpoint = "library/{$library_id}/videos";
        $data = ['title' => $title];

        return $this->sendJsonToBunny($endpoint, 'POST', $data);
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
     * Retrieve the playback URL of a video.
     *
     * @param string $videoId The ID of the video.
     * @return array|WP_Error The API response or WP_Error on failure.
     */
    public function getVideoPlaybackUrl($videoId) {
        $endpoint = "videos/{$videoId}/playback";
        return $this->sendJsonToBunny($endpoint, 'GET', []);
    }

    /**
     * Delete a collection by its ID.
     * 
     * @param string $collectionId The ID of the collection to delete.
     * @return bool|WP_Error True on success, or WP_Error on failure.
     */
    public function deleteCollection($collectionId, $userId = null) {
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to delete a collection.', 'wp-bunnystream'));
        }

        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required.', 'wp-bunnystream'));
        }

        $endpoint = "library/{$library_id}/collections/{$collectionId}";
        $response = $this->sendJsonToBunny($endpoint, 'DELETE');

        if (is_wp_error($response)) {
            return $response;
        }

        if ($userId) {
            $dbManager = new \WPBunnyStream\Integration\BunnyDatabaseManager();
            $dbManager->deleteUserCollection($userId);
        }

        return true;
    }

    /**
     * Get details of a specific collection.
     * 
     * @param string $collectionId The ID of the collection to retrieve.
     * @return array|WP_Error The collection details or WP_Error on failure.
     */
    public function getCollection($collectionId) {
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to fetch a collection.', 'wp-bunnystream'));
        }

        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required.', 'wp-bunnystream'));
        }

        $endpoint = "library/{$library_id}/collections/{$collectionId}";
        return $this->sendJsonToBunny($endpoint, 'GET');
    }

    /**
     * Update the details of an existing collection.
     * 
     * @param string $collectionId The ID of the collection to update.
     * @param array $data The updated data for the collection (e.g., name, metadata).
     * @return array|WP_Error The updated collection details or WP_Error on failure.
     */
    public function updateCollection($collectionId, $data) {
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to update a collection.', 'wp-bunnystream'));
        }

        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required.', 'wp-bunnystream'));
        }

        if (empty($data) || !is_array($data)) {
            return new \WP_Error('missing_update_data', __('Update data is required and must be an array.', 'wp-bunnystream'));
        }

        $endpoint = "library/{$library_id}/collections/{$collectionId}";
        return $this->sendJsonToBunny($endpoint, 'PUT', $data);
    }

    /**
     * Upload a video to Bunny.net.
     * 
     * @param string $filePath The path to the video file on the server.
     * @param string $collectionId The collection ID to associate the video with (optional).
     * @return array|WP_Error The API response or WP_Error on failure.
     */
    public function uploadVideo($filePath, $collectionId = null, $postId = null, $userId = null) {
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to upload a video.', 'wp-bunnystream'));
        }
    
        if (!file_exists($filePath)) {
            return new \WP_Error('file_not_found', __('The video file does not exist.', 'wp-bunnystream'));
        }
    
        // Validate file size
        $fileSize = filesize($filePath);
        if ($fileSize > self::MAX_FILE_SIZE) {
            return new \WP_Error('file_too_large', __('File exceeds maximum allowed size of 500MB.', 'wp-bunnystream'));
        }
    
        // Validate MIME type
        $mimeValidation = $this->validateMimeType($filePath);
        if (is_wp_error($mimeValidation)) {
            return $mimeValidation;
        }
    
        // Upload video to Bunny.net
        $endpoint = "library/{$library_id}/videos" . ($collectionId ? "?collection={$collectionId}" : "");
    
        $videoBaseUrl = $this->video_base_url;
        $uploadResponse = $this->retryApiCall(function() use ($endpoint, $filePath, $videoBaseUrl) {
            $fileHandle = fopen($filePath, 'r');
            if (!$fileHandle) {
                return new \WP_Error('file_error', __('Unable to open the video file for reading.', 'wp-bunnystream'));
            }
    
            $headers = [
                'AccessKey' => $this->access_key,
                'Content-Type' => 'application/octet-stream',
            ];
    
            $args = [
                'method' => 'POST',
                'headers' => $headers,
                'body' => $fileHandle,
                'timeout' => 300,
            ];
    
            $url = $this->video_base_url . ltrim($endpoint, '/');
            $response = wp_remote_request($url, $args);
            fclose($fileHandle);
    
            if (is_wp_error($response)) {
                return $response;
            }
    
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
    
            if ($response_code < 200 || $response_code >= 300) {
                return new \WP_Error(
                    'bunny_api_http_error',
                    sprintf(__('HTTP Error %d: %s (Endpoint: %s)', 'wp-bunnystream'), $response_code, $response_body, $endpoint)
                );
            }
    
            return json_decode($response_body, true);
        });
    
        if (is_wp_error($uploadResponse) || empty($uploadResponse['id'])) {
            return new \WP_Error('upload_failed', __('Failed to upload video to Bunny.net.', 'wp-bunnystream'));
        }
    
        $videoId = $uploadResponse['id'];
    
        // Retrieve playback URL
        $playbackResponse = $this->getVideoPlaybackUrl($videoId);
        if (is_wp_error($playbackResponse) || empty($playbackResponse['playbackUrl'])) {
            return new \WP_Error('playback_url_error', __('Failed to retrieve video playback URL.', 'wp-bunnystream'));
        }
    
        return [
            'videoId' => $videoId,
            'videoUrl' => $playbackResponse['playbackUrl']
        ];
    }         
}
