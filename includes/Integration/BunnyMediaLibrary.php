<?php
namespace WP_BunnyStream\Integration;

use WP_BunnyStream\Integration\BunnyApi;
use WP_BunnyStream\Integration\BunnyMetadataManager;
use WP_BunnyStream\Integration\BunnyApiKeyManager;

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
        $this->databaseManager = new BunnyApiKeyManager();

        add_filter('wp_handle_upload', [$this, 'interceptUpload'], 10, 2);
        add_action('add_attachment', [$this, 'handleAttachmentMetadata'], 10, 1);
    }

    /**
     * Helper function to log messages in a structured way.
     *
     * @param string $message The log message.
     * @param string $type    The type of message (info, warning, error).
     */
    private function log( $message, $type = 'info' ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $log_entry = sprintf( '[BunnyMediaLibrary] [%s] %s', strtoupper( $type ), $message );
            error_log( $log_entry );
        }
    }

    /**
     * Intercepts video uploads and, if applicable, offloads them to Bunny.net.
     *
     * @param array $upload Data array representing the uploaded file.
     * @param mixed $context Additional context.
     * @return array|WP_Error The modified upload array or WP_Error on failure.
     */
    public function interceptUpload( $upload, $context ) {
        // Only process video files.
        if ( ! isset( $upload['type'] ) || strpos( $upload['type'], 'video/' ) !== 0 ) {
            return $upload;
        }
    
        // Log that offloading will be handled later.
        $this->log( "interceptUpload: Video detected. Offloading will be handled on attachment creation.", 'info' );
    
        // Return the upload data unchanged.
        return $upload;
    }    

    /**
     * Offloads a video to Bunny.net with enhanced error handling and logging.
     *
     * @param array $upload  Data array representing the uploaded file.
     * @param int   $post_id The attachment post ID.
     * @param int   $user_id The ID of the user performing the upload.
     * @return array|WP_Error The modified upload array including Bunny.net video details or WP_Error on failure.
     */
    public function offloadVideo($upload, $post_id, $user_id) {

        $this->log("offloadVideo: Processing upload for post ID {$post_id}, user ID {$user_id}.", 'debug');

        // Validate file existence.
        if (!isset($upload['file']) || !file_exists($upload['file'])) {
            $this->log('Invalid file path provided for video offloading.', 'error');
            return new \WP_Error('invalid_file_path', __('The provided file path is invalid.', 'wp-bunnystream'));
        }
        $filePath = $upload['file'];
    
        // Validate MIME type.
        $mimeValidation = $this->bunnyApi->validateMimeType($filePath);
        if (is_wp_error($mimeValidation)) {
            $this->log('MIME type validation failed: ' . $mimeValidation->get_error_message(), 'error');
            return $mimeValidation;
        }
    
        // Retrieve user data.
        $user = get_userdata($user_id);
        if (!$user) {
            $this->log("Could not retrieve user data for user ID {$user_id}", 'error');
            return new \WP_Error('invalid_user', __('Invalid user specified.', 'wp-bunnystream'));
        }
    
        // Step 1: Retrieve or create the user's collection.
        $collectionId = get_user_meta($user_id, '_bunny_collection_id', true) 
        ?: $this->bunnyApi->createCollection($user_id);

        if (is_wp_error($collectionId)) {
        $this->log("Failed to create collection for user ID {$user_id}: " . $collectionId->get_error_message(), 'error');
        return $collectionId;
        }

        // Store the collection ID in user meta if it was newly created.
        if (!get_user_meta($user_id, '_bunny_collection_id', true)) {
            update_user_meta($user_id, '_bunny_collection_id', $collectionId);
            $this->log("Collection ID {$collectionId} assigned to user ID {$user_id}.", 'info');
        }
    
        // Step 2: Offload the video file using BunnyApi.
        $uploadResponse = $this->bunnyApi->uploadVideo($filePath, $collectionId, $post_id, $user_id);
        
        if (is_wp_error($uploadResponse)) {
            $this->log("Video upload failed: " . $uploadResponse->get_error_message(), 'error');
            return $uploadResponse;
        }
    
        // Step 3: Validate API response.
        if (!is_array($uploadResponse) || !isset($uploadResponse['videoId']) || empty($uploadResponse['videoUrl'])) {
            $this->log("Invalid API response received: " . json_encode($uploadResponse), 'error');
            return new \WP_Error('invalid_api_response', __('Bunny.net did not return a valid videoId or videoUrl.', 'wp-bunnystream'));
        }
    
        // Step 4: Optionally delete the local file.
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    
        // Step 5: Update the upload data with Bunny.net details.
        $upload['bunny_video_url'] = $uploadResponse['videoUrl'];
        $upload['video_id'] = $uploadResponse['videoId'];
    
        // Step 6: Store metadata for later reference.
        $this->metadataManager->storeVideoMetadata($post_id, [
            'source' => 'bunnycdn',
            'videoUrl' => $uploadResponse['videoUrl'],
            'collectionId' => $collectionId,
            'videoGuid' => $uploadResponse['videoId'],
        ]);
    
        $this->log("Video offloaded successfully. Video ID: " . $uploadResponse['videoId'], 'info');
        return $upload;
    }        

    /**
     * Store Bunny.net metadata when an attachment is added
     */
    public function handleAttachmentMetadata($post_id) {
        // Check if this attachment is a video.
        $mime = get_post_mime_type($post_id);
        if (strpos($mime, 'video/') !== 0) {
            return;
        }

        // Prevent WP Offload Media from triggering multiple uploads
        if (class_exists('AS3CF_Plugin')) {
            $this->log("handleAttachmentMetadata: WP Offload Media detected. Skipping duplicate execution.", 'warning');
            return;
        }
    
        // Prevent duplicate uploads using a transient lock
        $lock_key = "wpbs_video_upload_lock_{$post_id}";
        if (get_transient($lock_key)) {
            $this->log("handleAttachmentMetadata: Upload for post ID {$post_id} is already in progress.", 'warning');
            return;
        }
        set_transient($lock_key, true, 60); // Lock expires after 60 seconds
    
        // If offloading has already been done, skip.
        $bunny_video_id = get_post_meta($post_id, '_bunny_video_id', true);
        if (!empty($bunny_video_id)) {
            $this->log("handleAttachmentMetadata: Video already offloaded (ID: {$bunny_video_id}).", 'info');
            return;
        }
    
        // Retrieve the file path.
        $filePath = get_attached_file($post_id);
        if (!$filePath || !file_exists($filePath)) {
            $this->log("handleAttachmentMetadata: Invalid file path for post ID {$post_id}.", 'error');
            return;
        }
    
        // Get the user ID from the attachment post.
        $user_id = (int) get_post_field('post_author', $post_id);
        if (!$user_id) {
            $this->log("handleAttachmentMetadata: No user found for post ID {$post_id}.", 'error');
            return;
        }
    
        // Build a minimal upload array for offloadVideo().
        $upload_data = ['file' => $filePath];

        $this->log("handleAttachmentMetadata: Calling offloadVideo() for post ID {$post_id}.", 'debug');
    
        // Call offloadVideo() to offload the video.
        $result = $this->offloadVideo($upload_data, $post_id, $user_id);
        if (is_wp_error($result)) {
            $this->log("handleAttachmentMetadata: Offloading failed for post ID {$post_id}: " . $result->get_error_message(), 'error');
            return;
        }
    
        // Optionally, update the attachment's URL and metadata.
        if (isset($result['bunny_video_url'])) {
            update_post_meta($post_id, '_bunny_video_url', $result['bunny_video_url']);
            update_post_meta($post_id, '_bunny_video_id', $result['video_id']);
            $this->log("handleAttachmentMetadata: Offloading succeeded for post ID {$post_id}.", 'info');
        }
    }        
}

// Initialize the media library integration
new BunnyMediaLibrary();
