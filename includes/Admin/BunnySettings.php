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
use WP_BunnyStream\Integration\BunnyDatabaseManager;

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

    /**
     * Encrypt API key before storing it.
     */
    public static function encrypt_api_key($key) {
        $encryption_key = wp_salt();
        return base64_encode(openssl_encrypt($key, 'aes-256-cbc', $encryption_key, 0, substr($encryption_key, 0, 16)));
    }

    /**
     * Decrypt API key when retrieving it.
     */
    public static function decrypt_api_key($encrypted_key) {
        $encryption_key = wp_salt();
        return openssl_decrypt(base64_decode($encrypted_key), 'aes-256-cbc', $encryption_key, 0, substr($encryption_key, 0, 16));
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
        add_action('wp_ajax_bunny_create_library', [$this, 'handleCreateLibraryAjax']);

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
        register_setting('bunny_net_settings', self::OPTION_ACCESS_KEY, ['sanitize_callback' => function($value) { return BunnySettings::encrypt_api_key($value); }]);
        register_setting('bunny_net_settings', self::OPTION_LIBRARY_ID, ['sanitize_callback' => 'sanitize_text_field']);

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
            'bunny_library_creation',
            __('Library Creation', 'wp-bunnystream'),
            [$this, 'renderLibraryCreationField'],
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
        $value = esc_attr(get_option(self::OPTION_ACCESS_KEY, ''));
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
        $library_id = get_option(self::OPTION_LIBRARY_ID, '');
        echo "<input type='text' name='" . self::OPTION_LIBRARY_ID . "' value='$library_id' class='regular-text' />";
        echo '<p class="description">';
        echo esc_html__('When activated on a multisite network, a different library should be used for each site on the network to avoid duplicate naming conflicts of the user collections that are automatically generated.', 'wp-bunnystream');
        echo '</p>';
    }    

    /**
     * Render the Manual Video Creation button.
     */
    public function renderManualVideoCreationButton() {
        echo '<p>' . esc_html__('Before uploading any video content, you must first create a video object. A "test" video object is automatically created when the Library ID and API Key are initially saved or when they are changed, but you can manually create a new "test" video object if necessary.', 'wp-bunnystream') . '</p>';
        echo '<button id="bunny-create-video-object" class="button button-secondary">' . esc_html__('Create Video Object', 'wp-bunnystream') . '</button>';
    
        // Add JavaScript for the AJAX request
        echo '<script>
            document.getElementById("bunny-create-video-object").addEventListener("click", function(event) {
                event.preventDefault();
    
                let formData = new URLSearchParams();
                formData.append("action", "bunny_manual_create_video");
                formData.append("title", "test");
                formData.append("security", bunnyUploadVars.nonce); // Ensure nonce is included
    
                fetch(ajaxurl, {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert("Video object created successfully!");
                    } else {
                        const errorMessage = data.data?.message || "An unknown error occurred.";
                        alert("Error: " + errorMessage);
                        console.error("Error response:", data);
                    }
                })
                .catch(error => {
                    alert("An unexpected error occurred.");
                    console.error(error);
                });
            });
        </script>';
    }    

    // --- Continue in BunnySettings - Second Half ---

    /**
     * Render the Library Creation field.
     */
    public function renderLibraryCreationField() {
        echo '<p>' . esc_html__('Create a new video library if you don’t already have one.', 'wp-bunnystream') . '</p>';
        echo '<input type="text" id="bunny-library-name" placeholder="Enter Library Name" class="regular-text" />';
        echo '<button id="bunny-create-library" class="button button-primary">' . esc_html__('Create Library', 'wp-bunnystream') . '</button>';
        echo '<p id="bunny-library-creation-status"></p>';

        // Add AJAX script for handling library creation
        echo '<script>
            document.getElementById("bunny-create-library").addEventListener("click", function (event) {
                event.preventDefault();

                const libraryName = document.getElementById("bunny-library-name").value;

                if (!libraryName) {
                    alert("Please enter a library name.");
                    return;
                }

                fetch(ajaxurl, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: new URLSearchParams({
                        action: "bunny_create_library",
                        library_name: libraryName,
                        security: bunnyUploadVars.nonce // Ensure nonce is included
                    }),
                })
                    .then((response) => response.json())
                    .then((data) => {
                        const statusElement = document.getElementById("bunny-library-creation-status");
                        if (data.success) {
                            statusElement.textContent = "Library created successfully.";
                            document.getElementById("bunny-library-id").value = data.libraryId; // Dynamically update Library ID
                            document.getElementById("bunny-library-name").value = ""; // Clear the input field
                        } else {
                            statusElement.textContent = "Error: " + (data.message || "An unknown error occurred.");
                            console.error("Error response:", data);
                        }
                    })
                    .catch((error) => {
                        console.error("AJAX error:", error);
                        alert("An unexpected error occurred. Please check the console for more details.");
                    });
            });
        </script>';
    }

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
    public function handleManualVideoCreationAjax() {
        check_ajax_referer('bunny_nonce', 'security');

        $title = sanitize_text_field($_POST['title'] ?? 'test');
        $library_id = get_option(self::OPTION_LIBRARY_ID, '');

        if (empty($title) || empty($library_id)) {
            wp_send_json_error(['message' => __('Title or Library ID is missing.', 'wp-bunnystream')], 400);
        }

        // Check if collection already exists before creating a new one
        $dbManager = new \WP_BunnyStream\Integration\BunnyDatabaseManager();
        $collectionId = $dbManager->getUserCollectionId(get_current_user_id());

        if ($collectionId) {
            wp_send_json_success(['message' => __('Collection already exists.', 'wp-bunnystream')]);
        }

        $response = $this->bunnyApi->createVideoObject($title);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 500);
        }

        wp_send_json_success(['message' => __('Video object created successfully.', 'wp-bunnystream')]);
    }

    /**
     * Handle AJAX request to create a library.
     */
    public function handleCreateLibraryAjax() {
        check_ajax_referer('bunny_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'wp-bunnystream')], 403);
        }

        $libraryName = sanitize_text_field($_POST['library_name'] ?? '');

        if (empty($libraryName)) {
            wp_send_json_error(['message' => __('Library name is required.', 'wp-bunnystream')], 400);
        }

        $response = $this->bunnyApi->createLibrary($libraryName);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 500);
        }

        if (isset($response['guid'])) {
            update_option(self::OPTION_LIBRARY_ID, BunnySettings::encrypt_api_key($response['guid']));
            wp_send_json_success([
                'message' => __('Library created successfully.', 'wp-bunnystream'),
                'libraryId' => $response['guid'],
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to create library.', 'wp-bunnystream')], 500);
        }
    }
}
