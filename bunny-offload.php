<?php

// Bunny Media Library
// Enhanced fork of bunny.net WordPress Plugin
//
// Original: Copyright (C) 2024-2025 BunnyWay d.o.o.
// Fork: Copyright (C) 2025 Indigetal WebCraft
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
// ---
//
// This plugin is a fork of the bunny.net WordPress Plugin v2.3.5
// Original: https://wordpress.org/plugins/bunnycdn/
// 
// Enhanced for automatic media offloading with per-user organization.
// Simplified by removing full CDN acceleration features to focus on
// media offloading (Stream for video, Storage Zones for images/documents).
//
// Major changes from original:
// - Added automatic Media Library integration
// - Added per-user organization (collections for videos, folders for images)
// - Added bulk import/export tools
// - Removed full CDN acceleration (DNS, Pullzone management)
// - Removed font optimization
// - Removed image optimization
// - Simplified setup wizard

declare(strict_types=1);

// Don't load directly.
if (!defined('ABSPATH')) {
    exit('-1');
}

/*
Plugin Name: Bunny Offload Media Library
Plugin URI: https://github.com/yourusername/bunny-media-library
Description: Automatically offload WordPress media (videos, images, documents) to Bunny.net with per-user organization. Enhanced fork of bunny.net plugin focused on media offloading.
Version: 1.0.1-alpha
Requires at least: 6.7
Tested up to: 6.8
Requires PHP: 8.1
Author: Indigetal WebCraft
Author URI: https://indigetal.com
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: bunny-media-library
*/

const BUNNY_OFFLOAD_VERSION = '1.0.1-alpha';
const BUNNY_OFFLOAD_FORKED_FROM = '2.3.5'; // Original bunny.net plugin version

require_once __DIR__.'/src/functions.php';

// Plugin activation/uninstall hooks
register_activation_hook(__FILE__, 'bunnycdn_activate_plugin');
register_uninstall_hook(__FILE__, 'bunnycdn_uninstall_plugin');

add_action('upgrader_process_complete', function (\WP_Upgrader $upgrader, array $hook_extra) {
    if (!isset($hook_extra['type']) || 'plugin' !== $hook_extra['type']) {
        return;
    }

    // cleanup pre-v2.0.3 user info
    if ('agency' === get_option('bunnycdn_wizard_mode') && false !== get_option('bunnycdn_api_user')) {
        delete_option('bunnycdn_api_user');
    }
}, 10, 2);

add_action('init', function () {
    require_once __DIR__.'/vendor/autoload.php';

    \Bunny\Wordpress\Offloader::register();

    if (is_admin()) {
        require_once __DIR__.'/admin.php';
    } else {
        require_once __DIR__.'/frontend.php';
    }

    register_block_type(__DIR__.'/blocks/build/stream-video', [
        'render_callback' => 'bunnycdn_stream_video_render_block',
    ]);
});

add_action('rest_api_init', function () {
    $controller = bunnycdn_container()->newRestController();
    $controller->register();
});

add_shortcode('bunnycdn_stream_video', 'bunnycdn_stream_video_render_shortcode');
