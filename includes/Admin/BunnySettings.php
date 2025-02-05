<?php
/**
 * Bunny.net Settings Page
 * Provides a WordPress admin page for storing Bunny.net credentials.
 *
 * @package WPBunnyStream\Admin
 * @since 0.1.0
 */

namespace WP_BunnyStream\Admin;

use WP_BunnyStream\Integration\BunnyApi;
use WP_BunnyStream\Integration\BunnyApiKeyManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnySettings {

    /**
     * Option keys for storing credentials.
     */
    const OPTION_ACCESS_KEY = 'bunny_net_access_key';
    const OPTION_LIBRARY_ID = 'bunny_net_library_id';

    /**
     * BunnyApi instance.
     */
    private $bunnyApi;

    private static function getEncryptionKey() {
        if (!defined('BUNNY_SECRET_KEY')) {
            error_log('Bunny API Error: BUNNY_SECRET_KEY is missing! Using fallback key.');
            return hash('sha256', get_site_url()); // Fallback but not recommended for production
        }
        return BUNNY_SECRET_KEY;
    }        
    
    /**
     * Encrypt API key before storing it.
     */
    public static function encrypt_api_key($key) {
        return base64_encode(openssl_encrypt($key, 'aes-256-cbc', self::getEncryptionKey(), 0, substr(self::getEncryptionKey(), 0, 16)));
    }
    
    /**
     * Decrypt API key when retrieving it.
     */
    public static function decrypt_api_key($encrypted_key) {
        $decrypted = openssl_decrypt(base64_decode($encrypted_key), 'aes-256-cbc', self::getEncryptionKey(), 0, substr(self::getEncryptionKey(), 0, 16));
        if ($decrypted === false) {
            error_log('Bunny API Decryption Error: Unable to decrypt stored key.');
            return false; // Prevents silent errors
        }        
        return $decrypted;
    }    

    /**
     * Enqueue admin scripts for Bunny.net settings.
     */
    public function enqueueAdminScripts($hook) {
        if ($hook !== 'settings_page_bunny-net-settings') { // Only enqueue on settings page
            return;
        }

        wp_enqueue_script(
            'bunny-admin-script',
            plugin_dir_url(__FILE__) . '../../assets/js/bunny-admin.js',
            ['jquery'],
            null,
            true
        );

        wp_localize_script('bunny-admin-script', 'bunnyUploadVars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('bunny_nonce'),
        ]);
    }

    /**
     * Initialize the settings page.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);

        // AJAX handlers
        add_action('wp_ajax_bunny_manual_create_video', [$this, 'handleManualVideoCreationAjax']);

        // Initialize BunnyApi instance
        $this->bunnyApi = \BunnyApiInstance::getInstance();

        // Hook to check and create video object when options are updated
        add_action('update_option_' . self::OPTION_ACCESS_KEY, [$this, 'checkAndCreateVideoObject'], 10, 2);
        add_action('update_option_' . self::OPTION_LIBRARY_ID, [$this, 'checkAndCreateVideoObject'], 10, 2);
    }


    /**
     * Add the Bunny.net settings page to the WordPress admin menu.
     */
    public function addSettingsPage() {
        add_options_page(
            __('Bunny.net Settings', 'wp-bunnystream'),
            __('Bunny.net Settings', 'wp-bunnystream'),
            'manage_options',
            'bunny-net-settings',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Register settings for Bunny.net credentials.
     */
    public function registerSettings() {
        register_setting('bunny_net_settings', self::OPTION_ACCESS_KEY, [
            'sanitize_callback' => function($value) { return BunnySettings::encrypt_api_key($value); }
        ]);
        register_setting('bunny_net_settings', self::OPTION_LIBRARY_ID, [
            'sanitize_callback' => function($value) { return BunnySettings::encrypt_api_key($value); }
        ]);                

        add_settings_section(
            'bunny_net_credentials',
            __('Bunny.net Credentials', 'wp-bunnystream'),
            null,
            'bunny-net-settings'
        );

        add_settings_field(
            self::OPTION_ACCESS_KEY,
            __('Access Key', 'wp-bunnystream'),
            [$this, 'renderAccessKeyField'],
            'bunny-net-settings',
            'bunny_net_credentials'
        );

        add_settings_field(
            self::OPTION_LIBRARY_ID,
            __('Library ID', 'wp-bunnystream'),
            [$this, 'renderLibraryIdField'],
            'bunny-net-settings',
            'bunny_net_credentials'
        );

        add_settings_field(
            'bunny_manual_video_creation',
            __('Manual Video Creation', 'wp-bunnystream'),
            [$this, 'renderManualVideoCreationButton'],
            'bunny-net-settings',
            'bunny_net_credentials'
        );
    }

    /**
     * Render the Access Key field.
     */
    public function renderAccessKeyField() {
        $value = esc_attr(BunnySettings::decrypt_api_key(get_option(self::OPTION_ACCESS_KEY, '')));
        echo "<input type='text' name='" . self::OPTION_ACCESS_KEY . "' value='$value' class='regular-text' />";
        echo '<p class="description">';
        echo sprintf(
            __('To learn how to obtain your stream API key, see <a href="%s" target="_blank">How to obtain your Stream API key guide</a>.', 'wp-bunnystream'),
            'https://support.bunny.net/hc/en-us/articles/13503339878684-How-to-find-your-stream-API-key'
        );
        echo '</p>';
    }

    /**
     * Render the Library ID field.
     */
    public function renderLibraryIdField() {
        $library_id = esc_attr(BunnySettings::decrypt_api_key(get_option(self::OPTION_LIBRARY_ID, '')));
        echo "<input type='text' name='" . self::OPTION_LIBRARY_ID . "' value='$library_id' class='regular-text' />";
        echo '<p class="description">';
        echo esc_html__('When activated on a multisite network, a different library should be used for each site on the network to avoid duplicate naming conflicts of the user collections that are automatically generated.', 'wp-bunnystream');
        echo '</p>';
    }    

    /**
     * Check and create a video object if Access Key or Library ID changes.
     *
     * @param mixed $old_value The old option value.
     * @param mixed $value The new option value.
     */
    public function checkAndCreateVideoObject($old_value, $value) {
        $access_key = BunnySettings::decrypt_api_key(get_option(self::OPTION_ACCESS_KEY, ''));
        $library_id = BunnySettings::decrypt_api_key(get_option(self::OPTION_LIBRARY_ID, ''));


        if (!empty($access_key) && !empty($library_id)) {
            // Use the BunnyApi singleton instance
            $api = \WP_BunnyStream\Integration\BunnyApi::getInstance();

            // Validate API connection before creating a test video
            if (!$api || empty($api->library_id)) {
                error_log('Bunny API Error: Invalid or missing Library ID.');
                return;
            }

            // Create a test video object
            $response = $api->createVideoObject(__('Test Video', 'wp-bunnystream'));

            if (is_wp_error($response)) {
                error_log('Bunny API Error: ' . $response->get_error_message());
                set_transient('bunny_net_video_created', false, 60);
                return;
            }

            if (isset($response['guid'])) {
                error_log('Bunny API Success: Video object created with GUID ' . $response['guid']);
                set_transient('bunny_net_video_created', true, 60);
            } else {
                error_log('Bunny API Error: ' . json_encode($response));
                set_transient('bunny_net_video_created', false, 60);
            }
        } else {
            error_log('Bunny API Error: Missing Access Key or Library ID.');
        }
    }

    /**
     * Render the Manual Video Creation button.
     */
    public function renderManualVideoCreationButton() {
        echo '<p>' . esc_html__('Before uploading any video content, you must first create a video object. A "test" video object is automatically created when the Library ID and API Key are initially saved or when they are changed, but you can manually create a new "test" video object if necessary.', 'wp-bunnystream') . '</p>';
        echo '<button id="bunny-create-video-object" class="button button-secondary">' . esc_html__('Create Video Object', 'wp-bunnystream') . '</button>';
    }   

    // --- Continue in BunnySettings - Second Half ---

    /**
     * Render the settings page.
     */
    public function renderSettingsPage() {
        echo "<form action='options.php' method='post'>";
        settings_fields('bunny_net_settings');
        do_settings_sections('bunny-net-settings');
        submit_button(__('Save Settings', 'wp-bunnystream'));
        echo "</form>";
    }

    /**
     * Handle AJAX request to create a video object.
     */
    /**
     * Handle AJAX request to create a video object.
     */
    public function handleManualVideoCreationAjax() {
        check_ajax_referer('bunny_nonce', 'security');

        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : 'Test Video';
        $library_id = esc_attr(BunnySettings::decrypt_api_key(get_option(self::OPTION_LIBRARY_ID, '')));

        if (empty($title) || empty($library_id)) {
            wp_send_json_error(['message' => __('Title or Library ID is missing.', 'wp-bunnystream')], 400);
        }

        // Create a test video object without collections
        $response = $this->bunnyApi->createVideoObject($title);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 500);
        }

        wp_send_json_success(['message' => __('Video object created successfully.', 'wp-bunnystream')]);
    }

}
