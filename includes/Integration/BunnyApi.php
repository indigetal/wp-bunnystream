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

    /**
     * Helper function to log messages in a structured way.
     */
    private function log($message, $type = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_entry = sprintf('[BunnyAPI] [%s] %s', strtoupper($type), $message);
            error_log($log_entry);
        }
    }

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
                $this->log('Library ID is missing or not set.', 'warning');
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
            'Content-Length' => strlen(json_encode($data)), // Ensure correct length
        ];        
    
        // Build request arguments
        $args = [
            'method'  => $method,
            'headers' => $headers,
        ];
    
        // Add body if not a GET request
        if ($method !== 'GET' && !empty($data)) {
            $args['body'] = json_encode($data);
        }
    
        // Log API request details before making the request
        $this->log("Sending API request to Bunny.net. Endpoint: {$endpoint}, Method: {$method}, Library ID: {$this->library_id}", 'debug');
        $this->log("Headers: " . json_encode($headers), 'debug');
        if (!empty($data)) {
            $this->log("Request Body: " . json_encode($data), 'debug');
        }
    
        return $this->retryApiCall(function() use ($url, $args, $endpoint) {
            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                return $response;
            }
    
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response) ?: 'No response body';

            if ($response_code < 200 || $response_code >= 300) {
                $this->log("Failed Request to $endpoint (HTTP $response_code)", 'error');
                $this->log("Response Body: " . $response_body, 'debug');
                return new \WP_Error('bunny_api_http_error', sprintf(
                    __('Bunny.net API Error (HTTP %d): %s', 'wp-bunnystream'),
                    $response_code, 
                    $response_body
                ));
            }
    
            return json_decode($response_body, true);
        });
    }    

    /**
     * Retry failed API calls with exponential backoff and collection validation.
     */
    private function retryApiCall($callback, $maxAttempts = 3) {
        $attempt = 0;
    
        while ($attempt < $maxAttempts) {
            $this->log("API Attempt #" . ($attempt + 1), 'info');
    
            // Check if a transient exists for rate-limiting
            $retry_after_time = get_transient('bunny_api_retry_after');
            if ($retry_after_time && time() < $retry_after_time) {
                $wait_time = $retry_after_time - time();
                $this->log("Rate limited. Waiting {$wait_time} seconds before retrying...", 'warning');
                sleep($wait_time);
            }
    
            $response = $callback();
            if (!is_wp_error($response)) {
                $this->log("API Response (Success): " . json_encode($response), 'debug');
                return $response;
            }
    
            $error_message = $response->get_error_message();
            $response_code = wp_remote_retrieve_response_code($response);
    
            // Handle 429 Too Many Requests
            if ($response_code === 429) {
                $retry_after = wp_remote_retrieve_header($response, 'Retry-After');
                $retry_after_seconds = $retry_after ? (int) $retry_after : (2 ** $attempt);
    
                // Store retry-after in a transient to prevent immediate retries
                set_transient('bunny_api_retry_after', time() + $retry_after_seconds, $retry_after_seconds);
    
                $this->log("Rate limit hit (429). Respecting Retry-After: {$retry_after_seconds} seconds.", 'warning');
                sleep($retry_after_seconds);
            } else {
                // Log and retry with exponential backoff for other errors
                $this->log("API Call Failed (Error: {$error_message}). Retrying in " . (2 ** $attempt) . " seconds...", 'warning');
                sleep(2 ** $attempt);
            }
    
            $attempt++;
        }
    
        return new \WP_Error('api_failure', __('Bunny.net API failed after multiple attempts.', 'wp-bunnystream'));
    }              
    
    /**
     * Retrieve a list of all collections for a given video library.
     *
     * @return array|WP_Error The collection list or WP_Error on failure.
     */
    public function listCollections() {
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to fetch collections.', 'wp-bunnystream'));
        }

        $endpoint = "library/{$library_id}/collections?page=1&itemsPerPage=100";
        $response = $this->sendJsonToBunny($endpoint, 'GET');

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['items']) || !is_array($response['items'])) {
            return new \WP_Error('invalid_collection_list', __('Invalid response from Bunny.net when listing collections.', 'wp-bunnystream'));
        }

        return $response['items'];
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
    
        // Step 1: Prevent duplicate collection creation using a transient lock
        $lock_key = "wpbs_collection_lock_{$userId}";
        if (get_transient($lock_key)) {
            return new \WP_Error('collection_creation_locked', __('Collection creation is already in progress. Try again later.', 'wp-bunnystream'));
        }
    
        // Set transient lock to prevent simultaneous requests
        set_transient($lock_key, true, 10); // Lock expires after 10 seconds
    
        // Step 2: Check if the collection already exists on Bunny.net
        $collections = $this->listCollections();
        if (!is_wp_error($collections)) {
            foreach ($collections as $collection) {
                if ($collection['name'] === $collectionName) {
                    delete_transient($lock_key); // Remove lock since no new collection is needed
                    return $collection['guid']; // Return existing collection ID
                }
            }
        }
    
        // Step 3: Create the collection on Bunny.net
        $endpoint = "library/{$library_id}/collections";
        $data = array_merge(['name' => $collectionName], $additionalData);
        
        $response = $this->sendJsonToBunny($endpoint, 'POST', $data);
    
        // Remove the transient lock after request completes
        delete_transient($lock_key);
    
        if (is_wp_error($response) || empty($response['guid'])) {
            return new \WP_Error('collection_creation_failed', __('Failed to create collection on Bunny.net.', 'wp-bunnystream'));
        }
    
        return $response['guid']; // Always return only the GUID
    }                                            

    /**
     * Create a video object
     */
    public function createVideoObject($title, $collectionId) {
        $library_id = $this->getLibraryId();
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
    
        $response = $this->sendJsonToBunny("library/{$library_id}/videos", 'POST', $videoData);
    
        if (is_wp_error($response) || empty($response['guid'])) {
            return new \WP_Error('video_creation_failed', __('Failed to create video object.', 'wp-bunnystream'));
        }
    
        return $response['guid']; // Return video ID
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
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            $this->log('Library ID is missing or not set.', 'warning');
            return new \WP_Error('missing_library_id', __('Library ID is required to retrieve video playback URL.', 'wp-bunnystream'));
        }
    
        $endpoint = "library/{$library_id}/videos/{$videoId}";
        return $this->sendJsonToBunny($endpoint, 'GET');
    }
    
    /**
     * Async retry handler for fetching video playback URL.
     */
    public function retryFetchVideoPlaybackUrl($videoId, $postId) {
        $this->log("Retrying playback URL fetch for video ID: {$videoId}", 'info');

        $videoMetadata = $this->getVideoPlaybackUrl($videoId);
        if (is_wp_error($videoMetadata) || empty($videoMetadata['playbackUrl'])) {
            $this->log("Playback URL still not available for video ID: {$videoId}. No further retries.", 'error');
            return;
        }

        // Store the video playback URL in the post meta
        update_post_meta($postId, '_bunny_video_url', $videoMetadata['playbackUrl']);
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
            $this->log('Library ID is missing or not set.', 'warning');
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
            $dbManager = new \WP_BunnyStream\Integration\BunnyDatabaseManager();
            $dbManager->deleteUserCollection($userId);
        }
    
        return true;
    }    

    /**
     * Check if a specific collection exists in the list of collections.
     *
     * @param string $collectionId The ID of the collection to check.
     * @return array|WP_Error The collection details or WP_Error if it doesn't exist.
     */
    public function getCollection($collectionId) {
        $collections = $this->listCollections();
        
        if (is_wp_error($collections)) {
            return $collections; // Return error if listing collections fails
        }
    
        foreach ($collections as $collection) {
            if ($collection['guid'] === $collectionId) {
                return $collection;
            }
        }
    
        return null; // Instead of returning a WP_Error, return null if not found
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
            $this->log('Library ID is missing or not set.', 'warning');
            return new \WP_Error('missing_library_id', __('Library ID is required to update a collection.', 'wp-bunnystream'));
        }
    
        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required.', 'wp-bunnystream'));
        }
    
        if (empty($data) || !is_array($data)) {
            return new \WP_Error('missing_update_data', __('Update data is required and must be an array.', 'wp-bunnystream'));
        }
    
        $endpoint = "library/{$library_id}/collections/{$collectionId}";
        
        // Remove empty or unchanged values before sending the update
        $filteredData = array_filter($data, function($value) {
            return !is_null($value) && $value !== '';
        });
    
        if (empty($filteredData)) {
            return new \WP_Error('no_update_data', __('No changes detected for the collection update.', 'wp-bunnystream'));
        }
    
        return $this->sendJsonToBunny($endpoint, 'PUT', $filteredData);
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
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to upload a video.', 'wp-bunnystream'));
        }
    
        if (!is_string($filePath) || !file_exists($filePath)) {
            return new \WP_Error('invalid_file_path', __('Invalid file path for video upload.', 'wp-bunnystream'));
        }
    
        // Step 1: Ensure a valid collection ID exists before uploading
        if (!$collectionId && $userId) {
            $collectionId = get_user_meta($userId, '_bunny_collection_id', true);

            if ($collectionId) {
                // Validate if the collection exists on Bunny.net
                $collectionCheck = $this->getCollection($collectionId);

                if ($collectionCheck === null || is_wp_error($collectionCheck)) {
                    $this->log("Stored collection ID {$collectionId} not found on Bunny.net. Removing and creating a new one.", 'error');

                    // Step 1: Remove stale collection from user meta
                    delete_user_meta($userId, '_bunny_collection_id');
                    $collectionId = null; // Reset collectionId for re-creation

                    // Step 2: Create a new collection
                    $collectionId = $this->createCollection("wpbs_{$userId}", [], $userId);

                    // Step 3: Validate new collection creation
                    if (!$collectionId || is_wp_error($collectionId)) {
                        return new \WP_Error('collection_creation_failed', __('Collection creation failed, video upload aborted.', 'wp-bunnystream'));
                    }

                    // Step 4: Store new collection ID
                    update_user_meta($userId, '_bunny_collection_id', $collectionId);
                }
            }
        }
    
        // Step 2: Create a new video object
        $videoObjectResponse = $this->createVideoObject(basename($filePath), $collectionId);
    
        if (is_wp_error($videoObjectResponse) || empty($videoObjectResponse['guid'])) {
            return new \WP_Error('video_creation_failed', __('Failed to create video object.', 'wp-bunnystream'));
        }
    
        $videoId = $videoObjectResponse['guid'];
    
        // Step 3: Upload the video file using a PUT request
        $uploadEndpoint = "library/{$library_id}/videos/{$videoId}";
        $uploadResponse = $this->retryApiCall(function() use ($uploadEndpoint, $filePath) {
            $headers = [
                'AccessKey' => $this->access_key,
                'Content-Type' => 'application/octet-stream',
            ];
            return wp_remote_request($uploadEndpoint, [
                'method' => 'PUT',
                'headers' => $headers,
                'body' => file_get_contents($filePath),
                'timeout' => 300,
            ]);
        });
    
        if (is_wp_error($uploadResponse)) {
            return new \WP_Error('upload_failed', __('Failed to upload video to Bunny.net.', 'wp-bunnystream'));
        }
    
        // Step 4: Fetch video metadata to get playback URL
        $videoMetadata = $this->getVideoPlaybackUrl($videoId);
        if (is_wp_error($videoMetadata) || empty($videoMetadata['playbackUrl'])) {
            $this->log("Failed to retrieve playback URL for video ID: {$videoId}. Scheduling retry...", 'warning');

            // Schedule a retry event for fetching the playback URL
            wp_schedule_single_event(time() + 60, 'wpbs_retry_fetch_video_url', [$videoId, $postId]);

            return new \WP_Error('playback_url_failed', __('Playback URL not available yet. Retry scheduled in 60 seconds.', 'wp-bunnystream'));
        }
    
        return [
            'videoId' => $videoId,
            'videoUrl' => $videoMetadata['playbackUrl'],
        ];
    }                              
    
    /**
     * Set a thumbnail for a video in Bunny.net.
     *
     * @param string $videoId The ID of the video.
     * @param int|null $timestamp (Optional) The timestamp (in seconds) from which to generate the thumbnail.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function setThumbnail($videoId, $timestamp = null) {
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            $this->log('Library ID is missing or not set.', 'warning');
            return new \WP_Error('missing_library_id', __('Library ID is required to set a thumbnail.', 'wp-bunnystream'));
        }
    
        if (empty($videoId)) {
            return new \WP_Error('missing_video_id', __('Video ID is required to set a thumbnail.', 'wp-bunnystream'));
        }
    
        $endpoint = "library/{$library_id}/videos/{$videoId}/thumbnail";
    
        $data = [];
        if (!is_null($timestamp)) {
            $data['time'] = $timestamp;
        }
    
        $response = $this->sendJsonToBunny($endpoint, 'POST', $data);
    
        if (is_wp_error($response)) {
            error_log('Bunny API Error: Failed to set video thumbnail: ' . $response->get_error_message());
            return $response;
        }
    
        return true;
    }    
}
