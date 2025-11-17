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
 * @var array<string, int> $attachments
 * @var \Bunny\Wordpress\Config\Offloader $config
 */
?>
<div class="container bg-gradient bn-p-0 bn-pb-5">
    <section class="bn-section bn-section-hero bn-p-5">
        <div>
            <h1>Bunny Offloader</h1>
            <h2>CDN Configured But Offloader Not Enabled</h2>
            <p>We detected that CDN acceleration is configured, but the Bunny Offloader is not yet enabled. To prevent broken images and ensure media files are properly delivered, please enable the Content Offloader below.</p>
            <p class="bn-mt-3">The Offloader will automatically transfer your WordPress media files to Bunny Storage, making them available for CDN delivery.</p>
        </div>
        <img src="<?php echo esc_attr($this->assetUrl('offloader-header.svg')) ?>" alt="">
    </section>
    <div class="bn-m-5">
        <?php echo $this->renderPartialFile('cdn-acceleration.alert.php'); ?>
    </div>
    <section class="bn-section statistics bn-section--no-divider">
        <?php echo $this->renderPartialFile('offloader.statistics.php', ['attachments' => $attachments, 'config' => $config, 'attachmentsWithError' => 0]) ?>
    </section>
</div>
