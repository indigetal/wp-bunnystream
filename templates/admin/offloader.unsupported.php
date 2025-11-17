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

/**
 * @var \Bunny\Wordpress\Admin\Container $this
 */
?>
<div class="container bg-gradient bn-p-0 bn-pb-5">
    <section class="bn-section bn-section-hero bn-p-5">
        <div>
            <h1>Bunny Offloader</h1>
            <h2>Unsupported Configuration Detected</h2>
            <p>The Bunny Offloader automatically transfers WordPress media files to Bunny Storage for optimized delivery. However, your current WordPress configuration uses custom directory locations that are not supported.</p>
        </div>
        <img src="<?php echo esc_attr($this->assetUrl('offloader-header.svg')) ?>" alt="">
    </section>
    <div class="bn-m-5">
        <div class="alert red">
            <p><strong>Bunny Offloader is not supported on your WordPress installation.</strong></p>
            <p>We currently do not support installations that use customized <a href="https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#moving-wp-content-folder" target="_blank">wp-content</a> or <a href="https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#moving-uploads-folder" target="_blank">uploads</a> folder locations.</p>
            <p class="bn-mt-3">To use the Bunny Offloader, your WordPress installation must use the standard directory structure. If you need assistance with custom configurations, please <a href="https://dash.bunny.net/support/tickets" target="_blank">contact Bunny.net support</a>.</p>
        </div>
    </div>
</div>
