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
                $this->log("Failed Request to $endpoint (HTTP $response_code)", 'error');
                $this->log("Headers: " . json_encode($args['headers']), 'debug');
                $this->log("Method: " . $args['method'], 'debug');
            
                if (!empty($args['body'])) {
                    $this->log("Request Body: " . json_encode($args['body']), 'debug');
                }
            
                return new \WP_Error('bunny_api_http_error', sprintf(__('Bunny.net API Error (HTTP %d): %s', 'wp-bunnystream'), $response_code, $response_body));
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
            $this->log("API Attempt #" . ($attempt + 1), 'info');
            
            $response = $callback();
            if (!is_wp_error($response)) {
                return $response;
            }
    
            $this->log("API Call Failed. Retrying in " . (2 ** $attempt) . " seconds...", 'warning');
            sleep(2 ** $attempt); // Progressive delay
    
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
    public function createCollection($collectionName = null, $additionalData = [], $userId = null) {
        $library_id = $this->getLibraryId();
        if (empty($library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to create a collection.', 'wp-bunnystream'));
        }
    
        // Enforce proper naming convention: wpbs_{username}
        if (empty($collectionName)) { // Ensure a valid collection name
            if ($userId) {
                $user = get_userdata($userId);
                $username = $user ? sanitize_title($user->user_login) : "user_{$userId}";
                $collectionName = "wpbs_{$username}";
            } else {
                $collectionName = "wpbs_default"; // Fallback if user ID is unavailable
            }
        }
    
        $dbManager = new \WP_BunnyStream\Integration\BunnyDatabaseManager();
        
        // Correctly check for an existing collection using the User ID
        $existingCollection = $dbManager->getUserCollectionId($userId);
    
        if ($existingCollection) {
            $apiCheck = $this->getCollection($existingCollection);
            if (!is_wp_error($apiCheck) && isset($apiCheck['guid'])) {
                return $existingCollection; // Collection exists on Bunny.net
            }
            $this->log("Collection exists in database but not on Bunny.net. Recreating...", 'warning');
        }
    
        // Create a new collection on Bunny.net
        $endpoint = "library/{$library_id}/collections";
        $data = array_merge(['name' => $collectionName], $additionalData);
        
        $response = $this->sendJsonToBunny($endpoint, 'POST', $data);
    
        if (is_wp_error($response) || !isset($response['guid'])) {
            $this->log("Failed to create collection: " . json_encode($response), 'error');
            return new \WP_Error('collection_creation_failed', __('Failed to create collection on Bunny.net.', 'wp-bunnystream'));
        }
    
        $collectionId = $response['guid'];
    
        // Store the collection **only if Bunny.net confirmed it exists**
        if ($userId) {
            $dbManager->storeUserCollection($userId, $collectionId);
        }
    
        return $collectionId;
    }                            

    /**
     * Create a video object without a collection.
     */
    public function createVideoObject($title) {
        $library_id = $this->getLibraryId();
        if (empty($this->library_id)) {
            $this->library_id = BunnySettings::decrypt_api_key(get_option('bunny_net_library_id', ''));
            if (empty($this->library_id)) {
                $this->log('Library ID is missing or not set.', 'warning');
            }
        }        

        $endpoint = "library/{$library_id}/videos";
        $data = ['title' => $title];

        $response = $this->sendJsonToBunny($endpoint, 'POST', $data);

        if (is_wp_error($response)) {
            return $response;
        }

        // Enhanced error handling for missing GUID
        if (!isset($response['guid'])) {
            error_log('Bunny API Error: API response did not include a GUID. Response: ' . print_r($response, true));
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
        if (empty($this->library_id)) {
            $this->library_id = BunnySettings::decrypt_api_key(get_option('bunny_net_library_id', ''));
            if (empty($this->library_id)) {
                $this->log('Library ID is missing or not set.', 'warning');
            }
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
        if (empty($this->library_id)) {
            $this->library_id = BunnySettings::decrypt_api_key(get_option('bunny_net_library_id', ''));
            if (empty($this->library_id)) {
                $this->log('Library ID is missing or not set.', 'warning');
            }
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
        if (empty($this->library_id)) {
            $this->library_id = BunnySettings::decrypt_api_key(get_option('bunny_net_library_id', ''));
            if (empty($this->library_id)) {
                $this->log('Library ID is missing or not set.', 'warning');
            }
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
     * @param string $collectionId The collection ID to associate the video with (optional).
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
    
        // Ensure the collection exists before uploading
        if (!$collectionId && $userId) {
            $this->log("Collection ID missing. Checking database or creating a new one...", 'info');
            $collectionId = $this->databaseManager->getUserCollectionId($userId);
            
            if (!$collectionId) {
                $this->log("No existing collection found. Creating a new one for user {$userId}...", 'info');
                $collectionId = $this->createCollection(null, [], $userId);
            }
        }
    
        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID could not be determined.', 'wp-bunnystream'));
        }
    
        // Step 1: Create a new video object
        $videoObjectResponse = $this->sendJsonToBunny("library/{$library_id}/videos", 'POST', [
            'title' => basename($filePath),
            'collectionId' => $collectionId, // Ensure video is added to collection
        ]);
    
        if (is_wp_error($videoObjectResponse) || empty($videoObjectResponse['guid'])) {
            return new \WP_Error('video_creation_failed', __('Failed to create video object.', 'wp-bunnystream'));
        }
    
        $videoId = $videoObjectResponse['guid']; // Retrieve video ID from response
    
        // Step 2: Upload the video file
        $uploadEndpoint = "library/{$library_id}/videos/{$videoId}";
    
        $uploadResponse = $this->retryApiCall(function() use ($uploadEndpoint, $filePath) {
            $headers = [
                'AccessKey' => $this->access_key,
                'Content-Type' => 'application/octet-stream',
            ];
    
            $args = [
                'method' => 'PUT',
                'headers' => $headers,
                'body' => file_get_contents($filePath),
                'timeout' => 300,
            ];
    
            $response = wp_remote_request($uploadEndpoint, $args);
    
            return is_wp_error($response) ? $response : json_decode(wp_remote_retrieve_body($response), true);
        });
    
        if (is_wp_error($uploadResponse)) {
            $this->log("Bunny.net Video Upload Failed: " . $uploadResponse->get_error_message(), 'error');
            return new \WP_Error('upload_failed', __('Failed to upload video to Bunny.net.', 'wp-bunnystream'));
        }
    
        // Fetch video metadata to get playback URL
        $videoMetadata = $this->getVideoPlaybackUrl($videoId);
    
        if (is_wp_error($videoMetadata) || empty($videoMetadata['playbackUrl'])) {
            $this->log("Failed to retrieve playback URL for video ID: $videoId", 'warning');
            return [
                'videoId' => $videoId,
                'videoUrl' => '', // Return empty if metadata is unavailable
            ];
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
        if (empty($this->library_id)) {
            $this->library_id = BunnySettings::decrypt_api_key(get_option('bunny_net_library_id', ''));
            if (empty($this->library_id)) {
                $this->log('Library ID is missing or not set.', 'warning');
            }
        }        

        if (empty($videoId)) {
            return new \WP_Error('missing_video_id', __('Video ID is required to set a thumbnail.', 'wp-bunnystream'));
        }

        // Construct the endpoint
        $endpoint = "library/{$library_id}/videos/{$videoId}/thumbnail";

        // Prepare data (optional timestamp)
        $data = [];
        if (!is_null($timestamp)) {
            $data['time'] = $timestamp; // Bunny.net allows selecting a timestamp
        }

        // Send API request
        $response = $this->sendJsonToBunny($endpoint, 'POST', $data);

        if (is_wp_error($response)) {
            error_log('Bunny API Error: Failed to set video thumbnail: ' . $response->get_error_message());
            return $response;
        }

        return true; // Successfully set the thumbnail
    }
                 
}
