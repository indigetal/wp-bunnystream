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
            $response_body = wp_remote_retrieve_body($response);
    
            if ($response_code < 200 || $response_code >= 300) {
                $this->log("Failed Request to $endpoint (HTTP $response_code)", 'error');
                $this->log("Response Body: " . $response_body, 'debug');
                return new \WP_Error('bunny_api_http_error', sprintf(__('Bunny.net API Error (HTTP %d): %s', 'wp-bunnystream'), $response_code, $response_body));
            }
    
            return json_decode($response_body, true);
        });
    }    

    /**
     * Retry failed API calls with exponential backoff and collection validation.
     */
    private function retryApiCall($callback, $maxAttempts = 3, $collectionId = null, $userId = null) {
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $this->log("API Attempt #" . ($attempt + 1), 'info');
            
            $response = $callback();
            if (!is_wp_error($response)) {
                $this->log("API Response (Success): " . json_encode($response), 'debug');
                return $response;
            }

            $error_message = $response->get_error_message();
            $response_code = is_wp_error($response) ? null : wp_remote_retrieve_response_code($response);

            // Detect a 404 error (potentially invalid collection ID)
            if ($response_code === 404) {
                $this->log("API returned 404 (Attempt #{$attempt}). Possible missing collection ID: {$collectionId}. Retrying...", 'warning');

                if ($attempt === 0) {
                    $this->log("Retrying once more to rule out transient issues...", 'info');
                    sleep(2); // Short delay before retry
                    $attempt++;
                    continue;
                }

                // Step 1: Confirm if the collection is truly missing
                if ($collectionId && $userId) {
                    $collectionCheck = $this->getCollection($collectionId);
                    if (is_wp_error($collectionCheck)) {
                        $this->log("Confirmed: Collection {$collectionId} is missing. Recreating a new one.", 'error');

                        // Step 2: Delete old collection ID from database
                        $this->databaseManager->deleteUserCollection($userId);

                        // Step 3: Create a new collection
                        $collectionId = $this->createCollection("wpbs_{$userId}", [], $userId);
                        if (is_wp_error($collectionId)) {
                            return new \WP_Error('collection_creation_failed', __('Failed to create new collection after API failure.', 'wp-bunnystream'));
                        }

                        $this->log("New collection {$collectionId} assigned to user {$userId}. Retrying API call.", 'info');

                        // Step 4: Retry the original API call with the new collection
                        return $callback();
                    }
                }
            }

            // Exponential backoff before retrying
            $this->log("API Call Failed. Retrying in " . (2 ** $attempt) . " seconds...", 'warning');
            sleep(2 ** $attempt);
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
            $this->log("Missing Library ID when attempting to create a collection.", 'error');
            return new \WP_Error('missing_library_id', __('Library ID is required to create a collection.', 'wp-bunnystream'));
        }
    
        if (empty($collectionName)) {
            $this->log("Missing Collection Name for user ID: {$userId}", 'error');
            return new \WP_Error('missing_collection_name', __('Collection name is required.', 'wp-bunnystream'));
        }
    
        $dbManager = new \WP_BunnyStream\Integration\BunnyDatabaseManager();
        
        // Step 1: Check if the collection exists on Bunny.net
        $existingCollection = $dbManager->getUserCollectionId($userId);
        if ($existingCollection) {
            $apiCheck = $this->getCollection($existingCollection);
            if (!is_wp_error($apiCheck) && isset($apiCheck['guid'])) {
                $this->log("Collection already exists on Bunny.net with ID: {$existingCollection}", 'info');
                return $existingCollection;
            }
            $this->log("Collection ID {$existingCollection} found in database but missing on Bunny.net. Recreating...", 'warning');
        }
    
        // Step 2: Create the collection on Bunny.net
        $endpoint = "library/{$library_id}/collections";
        $data = array_merge(['name' => $collectionName], $additionalData);
    
        $response = $this->sendJsonToBunny($endpoint, 'POST', $data);
        if (is_wp_error($response) || !isset($response['guid'])) {
            $this->log("Failed to create collection for user {$userId}. Bunny.net API response: " . json_encode($response), 'error');
            return new \WP_Error('collection_creation_failed', __('Failed to create collection on Bunny.net.', 'wp-bunnystream'));
        }
    
        $collectionId = $response['guid'];
    
        // Step 3: Store the collection **only if Bunny.net confirmed it exists**
        if ($userId) {
            $dbManager->storeUserCollection($userId, $collectionId);
            $this->log("Collection {$collectionId} successfully created and assigned to user {$userId}.", 'info');
        } else {
            // If creation failed, ensure no orphaned database entries exist
            if (!empty($userId)) { 
                $dbManager->deleteUserCollection($userId);
            }
        }        
        return $collectionId;
    }                                    

    /**
     * Create a video object without a collection.
     */
    public function createVideoObject($title) {
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            $this->log('Library ID is missing or not set.', 'warning');
            return new \WP_Error('missing_library_id', __('Library ID is required to create a video object.', 'wp-bunnystream'));
        }
    
        $endpoint = "library/{$library_id}/videos";
        $data = ['title' => $title];
    
        $response = $this->sendJsonToBunny($endpoint, 'POST', $data);
    
        if (is_wp_error($response)) {
            return $response;
        }
    
        // Enhanced error handling for missing GUID
        if (!isset($response['guid'])) {
            $this->log('Bunny API Error: API response did not include a GUID. Response: ' . json_encode($response), 'error');
            return new \WP_Error('video_creation_failed', __('Failed to retrieve video GUID after creation.', 'wp-bunnystream'));
        }
    
        return ['guid' => $response['guid']];
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
        $response = $this->sendJsonToBunny($endpoint, 'GET');
    
        if (is_wp_error($response)) {
            return $response;
        }
    
        if (!isset($response['guid']) || !isset($response['name'])) {
            $this->log("Invalid collection response: " . json_encode($response), 'error');
            return new \WP_Error('invalid_collection_data', __('Bunny.net returned an incomplete collection response.', 'wp-bunnystream'));
        }
    
        return $response;
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
            $collectionId = $this->databaseManager->getUserCollectionId($userId);
            if (!$collectionId) {
                $collectionId = $this->createCollection("wpbs_{$userId}", [], $userId);
                if (is_wp_error($collectionId)) {
                    return $collectionId;
                }
                $this->databaseManager->storeUserCollection($userId, $collectionId);
            }
        }
    
        // Step 2: Create a new video object
        $videoData = ['title' => basename($filePath)];
        if (!empty($collectionId)) {
            $videoData['collectionId'] = $collectionId;
        }
    
        $videoObjectResponse = $this->sendJsonToBunny("library/{$library_id}/videos", 'POST', $videoData);
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
            return new \WP_Error('playback_url_failed', __('Failed to retrieve playback URL.', 'wp-bunnystream'));
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
