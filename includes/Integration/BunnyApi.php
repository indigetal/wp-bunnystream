<?php

namespace Tutor\BunnyNetIntegration\Integration;

class BunnyApi {
    private $video_base_url = 'https://video.bunnycdn.com/'; // For video-related actions
    private $library_base_url = 'https://api.bunny.net/';    // For library-related actions
    private $access_key;
    private $library_id;

    public function __construct($access_key, $library_id) {
        $this->access_key = $access_key;
        $this->library_id = $library_id;
    }

    /**
     * Generic method to send JSON requests to Bunny.net
     */
    private function sendJsonToBunny($endpoint, $method, $data = [], $useLibraryBase = false) {
        $base_url = $useLibraryBase ? $this->library_base_url : $this->video_base_url;
        $url = $base_url . ltrim($endpoint, '/');

        // Validate HTTP method
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            return new \WP_Error('invalid_http_method', __('Invalid HTTP method provided.', 'tutor-lms-bunnynet-integration'));
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

        // Debug logging
        error_log('Bunny API Request: ' . print_r(compact('url', 'args'), true));

        // Send request
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response; // Return WP_Error for error handling
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code < 200 || $response_code >= 300) {
            return new \WP_Error(
                'bunny_api_http_error',
                sprintf(__('HTTP Error %d: %s (Endpoint: %s)', 'tutor-lms-bunnynet-integration'), $response_code, $response_body, $endpoint)
            );
        }

        return json_decode($response_body, true);
    }

    /**
     * Create a new video library.
     */
    public function createLibrary($libraryName) {
        if (empty($libraryName)) {
            return new \WP_Error('missing_library_name', __('Library name is required to create a new library.', 'tutor-lms-bunnynet-integration'));
        }
    
        $endpoint = 'videolibrary'; // Library management endpoint
        $data = [
            'name' => $libraryName,
            'readOnly' => false,
            'replicationRegions' => [], // Optional: Update this based on desired regions
        ];
    
        $response = $this->sendJsonToBunny($endpoint, 'POST', $data, true); // Use library_base_url
    
        if (is_wp_error($response)) {
            return $response;
        }
    
        if (isset($response['guid'])) {
            return $response['guid'];
        }
    
        return new \WP_Error('library_creation_failed', __('Library creation failed. Response did not include a library ID.', 'tutor-lms-bunnynet-integration'));
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
     * Check the transcoding status of a video.
     *
     * @param string $videoId The ID of the video.
     * @return array|WP_Error The API response or WP_Error on failure.
     */
    public function getVideoStatus($videoId) {
        $endpoint = "videos/{$videoId}/status";
        return $this->sendJsonToBunny($endpoint, 'GET', []);
    }

    /**
     * Check if a video has been successfully created.
     *
     * @param string $videoId The ID of the video.
     * @return bool|WP_Error True if the video is created, or WP_Error on failure.
     */
    public function isVideoCreated($videoId) {
        $status = $this->getVideoStatus($videoId);

        if (is_wp_error($status)) {
            return $status;
        }

        // Check if the status indicates the video is created
        return isset($status['status']) && $status['status'] === 'Success';
    }

        /**
     * Create a new collection within a library.
     *
     * @param string $collectionName The name of the collection.
     * @return array|WP_Error The created collection data or WP_Error on failure.
     */
    public function createCollection($collectionName) {
        if (empty($this->library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to create a collection.', 'tutor-lms-bunnynet-integration'));
        }

        if (empty($collectionName)) {
            return new \WP_Error('missing_collection_name', __('Collection name is required.', 'tutor-lms-bunnynet-integration'));
        }

        $endpoint = "library/{$this->library_id}/collections";
        $data = [
            'name' => $collectionName,
        ];

        $response = $this->sendJsonToBunny($endpoint, 'POST', $data);

        if (is_wp_error($response)) {
            return $response;
        }

        return isset($response['id']) ? $response : new \WP_Error('collection_creation_failed', __('Failed to create collection.', 'tutor-lms-bunnynet-integration'));
    }

    /**
     * Delete a collection by its ID.
     *
     * @param string $collectionId The ID of the collection to delete.
     * @return bool|WP_Error True on success, or WP_Error on failure.
     */
    public function deleteCollection($collectionId) {
        if (empty($this->library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to delete a collection.', 'tutor-lms-bunnynet-integration'));
        }

        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required.', 'tutor-lms-bunnynet-integration'));
        }

        $endpoint = "library/{$this->library_id}/collections/{$collectionId}";
        $response = $this->sendJsonToBunny($endpoint, 'DELETE');

        if (is_wp_error($response)) {
            return $response;
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
        if (empty($this->library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to fetch a collection.', 'tutor-lms-bunnynet-integration'));
        }

        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required.', 'tutor-lms-bunnynet-integration'));
        }

        $endpoint = "library/{$this->library_id}/collections/{$collectionId}";
        return $this->sendJsonToBunny($endpoint, 'GET');
    }

    /**
     * Update the details of an existing collection.
     *
     * @param string $collectionId The ID of the collection to update.
     * @param array $data The updated data for the collection.
     * @return array|WP_Error The updated collection details or WP_Error on failure.
     */
    public function updateCollection($collectionId, $data) {
        if (empty($this->library_id)) {
            return new \WP_Error('missing_library_id', __('Library ID is required to update a collection.', 'tutor-lms-bunnynet-integration'));
        }

        if (empty($collectionId)) {
            return new \WP_Error('missing_collection_id', __('Collection ID is required.', 'tutor-lms-bunnynet-integration'));
        }

        $endpoint = "library/{$this->library_id}/collections/{$collectionId}";
        return $this->sendJsonToBunny($endpoint, 'PUT', $data);
    }
    
}
