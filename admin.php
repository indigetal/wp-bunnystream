<?php

// bunny.net WordPress Plugin
// Copyright (C) 2024-2025 BunnyWay d.o.o.
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

declare(strict_types=1);

// Don't load directly.
if (!defined('ABSPATH')) {
    exit('-1');
}

// Legacy migrations removed (V1 and WP 6.5 Pullzone CORS)
// Only excluded extensions migration remains (storage-related, not CDN)

// migrate excluded extensions to excluded paths
if (get_option('bunnycdn_wizard_finished') && !get_option('_bunnycdn_migrated_excluded_extensions')) {
    try {
        bunnycdn_container()->newMigrateExcludedExtensions()->perform();
    } catch (\Exception $e) {
        trigger_error('bunnycdn: could not migrate excluded paths '.$e->getMessage(), \E_USER_WARNING);
    }
}

add_action('admin_menu', function () {
    add_menu_page(
        'bunny.net',
        'bunny.net',
        'manage_options',
        'bunnycdn',
        function () {
            (new \Bunny\Wordpress\Admin\Router(bunnycdn_admin_container()))->route();
        },
        'dashicons-carrot'
    );

    if ('1' !== get_option('bunnycdn_wizard_finished')) {
        return;
    }

    $isAgencyMode = 'agency' === get_option('bunnycdn_wizard_mode', 'standalone');
    add_submenu_page('bunnycdn', 'bunny.net', $isAgencyMode ? __('Overview', 'bunny-offload') : __('Overview', 'bunny-offload'), 'manage_options', 'bunny-offload');

    // Simplified menu - CDN, Optimizer, Fonts removed
    $submenus = [
        'offloader' => 'Offloader',
        'stream' => 'Stream',
    ];

    if ($isAgencyMode) {
        // Agency mode shows Offloader and Stream (no CDN features)
        // Keep both submenus
    }

    foreach ($submenus as $slug => $text) {
        add_submenu_page(
            'bunnycdn',
            $text,
            $text,
            'manage_options',
            'admin.php?page=bunnycdn&section='.$slug,
        );
    }
});

add_filter('submenu_file', function ($submenu_file, $parent_file) {
    if ('bunnycdn' === $parent_file) {
        $section = sanitize_key($_GET['section'] ?? '');
        if (strlen($section) > 0) {
            return 'admin.php?page=bunnycdn&section='.$section;
        }
    }

    return $submenu_file;
}, 10, 2);

add_action('wp_ajax_bunnycdn', function () {
    (new \Bunny\Wordpress\Admin\Router(bunnycdn_admin_container()))->route(true);
});

add_action('load-toplevel_page_bunnycdn', function () {
    $container = bunnycdn_admin_container();

    // Attachment controller removed - inline attachment management no longer needed
    // Attachments now managed through standard WordPress Media Library

    $isAgencyMode = 'agency' === get_option('bunnycdn_wizard_mode', 'standalone');
    $section = sanitize_key($_GET['section'] ?? '');
    $comboboxSections = ['offloader']; // CDN removed

    if (($isAgencyMode && '' === $section) || !$isAgencyMode && in_array($section, $comboboxSections, true)) {
        add_action('wp_print_scripts', function () use ($container) {
            echo '<script type="importmap">{"imports":{"@github/combobox-nav": "'.$container->assetUrl('combobox.github.js').'"}}</script>';
        }, 1, 0);
    }

    wp_enqueue_script('bunnycdn-admin', $container->assetUrl('admin.js'), [], BUNNY_OFFLOAD_VERSION);
    wp_enqueue_script('bunnycdn-admin-redirect', $container->assetUrl('redirect.js'), [], BUNNY_OFFLOAD_VERSION, true);
    wp_enqueue_style('bunnycdn-admin', $container->assetUrl('admin.css'), [], BUNNY_OFFLOAD_VERSION);

    if ('stream' === $section) {
        add_thickbox();
        wp_enqueue_script('bunnycdn-admin-thickbox', $container->assetUrl('admin-thickbox.js'), [], BUNNY_OFFLOAD_VERSION);
        wp_enqueue_script('bunnycdn-slimselect', $container->assetUrl('slimselect.min.js'), [], BUNNY_OFFLOAD_VERSION);
        wp_enqueue_style('bunnycdn-slimselect', $container->assetUrl('slimselect.min.css'), [], BUNNY_OFFLOAD_VERSION);
    }
});

add_action('admin_notices', function () {
    $migrationWarning = get_option('_bunnycdn_migration_warning');
    if (!empty($migrationWarning)) {
        $url = add_query_arg([
            'page' => 'bunnycdn',
        ], admin_url('admin.php'));

        bunny_polyfill_wp_admin_notice(
            str_replace('%url%', $url, $migrationWarning),
            ['type' => 'error', 'dismissible' => true],
        );
    }

    if (bunnycdn_container()->getOffloaderUtils()->shouldShowSyncDelayedMessage()) {
        bunny_polyfill_wp_admin_notice(
            sprintf(
                /* translators: 1: <a href=...> 2: </a> */
                esc_html__('bunny.net: There was an issue while offloading your files to the Edge Storage. To get help, please %1$sreach out to our Super Bunnies%2$s.', 'bunny-offload'),
                '<a href="https://dash.bunny.net/support/tickets" target="_blank">',
                '</a>'
            ),
            ['type' => 'error', 'dismissible' => true],
        );
    }
});

function bunnycdn_admin_container(): \Bunny\Wordpress\Admin\Container
{
    static $container;

    if (null !== $container) {
        return $container;
    }

    $container = new \Bunny\Wordpress\Admin\Container(
        bunnycdn_container(),
        plugin_dir_url(__FILE__),
        dirname(__FILE__).'/templates/admin',
    );

    return $container;
}
