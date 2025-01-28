<?php
/**
 * Integrate Bunny.net as a video source in Tutor LMS.
 *
 * @package TutorLMSBunnyNetIntegration\Integration
 * @since v2.0.0
 */

namespace Tutor\BunnyNetIntegration\Integration;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * BunnyNet Integration Class
 * Handles Bunny.net video source integration with Tutor LMS.
 */
class BunnyNet {

    /**
     * Constructor to register action & filter hooks.
     *
     * @since v2.0.0
     */
    public function __construct() {
        add_filter('tutor_preferred_video_sources', [__CLASS__, 'registerVideoSource']);
        add_action('tutor_after_video_meta_box_item', [__CLASS__, 'addVideoUploadButton'], 10, 2);
        add_action('wp_ajax_bunnynet_upload_video', [__CLASS__, 'handleVideoUpload']);
    }

    /**
     * Register Bunny.net as a video source.
     *
     * @param array $video_sources Existing video sources.
     * @return array Modified video sources.
     */
    public static function registerVideoSource(array $video_sources): array {
        $video_sources['bunnynet'] = [
            'title' => __('Bunny.net', 'tutor-lms-bunnynet-integration'),
            'icon'  => 'bunnynet',
        ];

        return $video_sources;
    }

    /**
     * Add a Bunny.net video upload button to the lesson meta box.
     *
     * @param string $style The display style.
     * @param object $post The post object.
     * @return void
     */
    public static function addVideoUploadButton(string $style, $post): void {
        $video_data = maybe_unserialize(get_post_meta($post->ID, '_video', true));
        $bunnynet_video_id = $video_data['source_bunnynet'] ?? '';
        ?>
        <div class="video-source-item video-source-bunnynet" style="<?php echo esc_attr($style); ?>">
            <label for="bunnynet-video-upload">
                <?php esc_html_e('Upload Video to Bunny.net', 'tutor-lms-bunnynet-integration'); ?>
            </label>
            <input 
                id="bunnynet-video-upload" 
                type="file" 
                accept="video/*" 
                data-bunnynet-upload="true"
            />
            <p class="description">
                <?php esc_html_e('Upload your video directly to Bunny.net for optimized playback.', 'tutor-lms-bunnynet-integration'); ?>
            </p>
        </div>
        <script>
            jQuery(document).ready(function($) {
                $('#bunnynet-video-upload').on('change', function(event) {
                    const file = event.target.files[0];
                    if (!file) return;

                    const formData = new FormData();
                    formData.append('action', 'bunnynet_upload_video');
                    formData.append('file', file);
                    formData.append('post_id', <?php echo (int) $post->ID; ?>);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function(response) {
                            if (response.success) {
                                alert('Video uploaded successfully!');
                            } else {
                                alert('Error: ' + response.data.message);
                            }
                        },
                        error: function() {
                            alert('An unexpected error occurred.');
                        },
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Handle AJAX video upload.
     *
     * @return void
     */
    public static function handleVideoUpload(): void {
        // Verify user permissions.
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'tutor-lms-bunnynet-integration')], 403);
        }

        // Check for uploaded file.
        if (empty($_FILES['file']) || empty($_POST['post_id'])) {
            wp_send_json_error(['message' => __('Missing file or post ID.', 'tutor-lms-bunnynet-integration')], 400);
        }

        $file = $_FILES['file'];
        $post_id = (int) sanitize_text_field($_POST['post_id']);

        // Call Bunny.net API to upload the video.
        $bunny_api = $GLOBALS['bunny_net_api'] ?? null;
        if (!$bunny_api) {
            wp_send_json_error(['message' => __('Bunny.net API is not initialized.', 'tutor-lms-bunnynet-integration')], 500);
        }

        $response = $bunny_api->uploadVideo($file['tmp_name'], $file['name']);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 500);
        }

        // Save video URL to post metadata.
        $video_data = [
            'source' => 'bunnynet',
            'source_bunnynet' => $response['url'] ?? '',
        ];
        update_post_meta($post_id, '_video', $video_data);

        wp_send_json_success(['message' => __('Video uploaded successfully.', 'tutor-lms-bunnynet-integration')]);
    }
}
