<?php
/**
 * Bunny.net Settings Page
 * Provides a WordPress admin page for storing Bunny.net credentials.
 *
 * @package WPBunnyStream\Admin
 * @since 0.1.0
 */

namespace WP_BunnyStream\Admin;

use WP_BunnyStream\API\BunnyApi;
use WP_BunnyStream\API\BunnyApiKeyManager;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class BunnySettings {

    /**
     * Option keys for storing credentials.
     */
    const OPTION_ACCESS_KEY = 'bunny_net_access_key';
    const OPTION_LIBRARY_ID = 'bunny_net_library_id';
    const OPTION_PULL_ZONE = 'bunny_net_pull_zone';

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

        // Initialize BunnyApi instance
        $this->bunnyApi = \BunnyApiInstance::getInstance();
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
        register_setting('bunny_net_settings', self::OPTION_PULL_ZONE, [
            'sanitize_callback' => 'sanitize_text_field'
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
            self::OPTION_PULL_ZONE,
            __('Pull Zone', 'wp-bunnystream'),
            [$this, 'renderPullZoneField'],
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
     * Render the Pull Zone field.
     */
    public function renderPullZoneField() {
        $pull_zone = esc_attr(get_option(self::OPTION_PULL_ZONE, ''));
        echo "<input type='text' name='" . self::OPTION_PULL_ZONE . "' value='$pull_zone' class='regular-text' />";
        echo '<p class="description">';
        echo __('You can locate your Pull Zone at <strong>Stream > Your Library > API > Manage</strong>. Please include the full hostname.', 'wp-bunnystream');
        echo '</p>';
    } 

    /**
     * Render the settings page.
     */
    public function renderSettingsPage() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Bunny.net Settings', 'wp-bunnystream') . '</h1>';

        // Display instructions for adding the Secret Key to wp-config.php
        echo '<div style="background: #f4f4f4; padding: 15px; border-left: 5px solid #007cba; margin-bottom: 20px;">';
        echo '<p><strong>' . esc_html__('Important:', 'wp-bunnystream') . '</strong> ';
        echo esc_html__('To securely store your Bunny.net API credentials, you must add a secret key to your site’s wp-config.php file.', 'wp-bunnystream');
        echo '</p>';
        echo '<pre style="background: #eaeaea; padding: 8px; border-radius: 4px; font-family:monospace;">';
        echo esc_html("define('BUNNY_SECRET_KEY', 'your-secret-key');");
        echo '</pre>';
        echo '<p>' . esc_html__('Choose a strong, unique key. Do not change this key after saving credentials, or decryption will fail.', 'wp-bunnystream') . '</p>';
        echo '</div>';

        // Render the settings form
        echo "<form action='options.php' method='post'>";
        settings_fields('bunny_net_settings');
        do_settings_sections('bunny-net-settings');
        submit_button(__('Save Settings', 'wp-bunnystream'));
        echo "</form>";
        echo '</div>';
    }

}
