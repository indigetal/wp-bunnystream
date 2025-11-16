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
            <h1>Bunny Storage</h1>
            <h2>What is Content Offloading?</h2>
            <p>Improve your website performance and user experience. Reduce load times and increase conversion rates in just a few clicks. Get hopping in under 5 minutes without writing a single line of code.</p>
        </div>
        <img src="<?php echo esc_attr($this->assetUrl('offloader-header.svg')) ?>" alt="">
    </section>
    <div class="bn-m-5">
        <div class="alert red">
            <p>Content Offloading is not supported on your WordPress installation.</p>
            <p>We currently do not support installations that make use of customized <a href="https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#moving-wp-content-folder" target="_blank">wp-content</a> or <a href="https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#moving-uploads-folder" target="_blank">uploads</a> folder locations.</p>
        </div>
    </div>
</div>
