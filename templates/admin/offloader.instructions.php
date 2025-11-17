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
 * @var bool $showApiKeyAlert
 * @var bool $showCdnAccelerationAlert
 */
?>
<form class="container bg-gradient bn-p-0" method="POST" autocomplete="off">
    <section class="bn-section bn-section-hero bn-p-5">
        <div>
            <h1>Bunny Offloader</h1>
            <p class="bn-text-200-regular">
                Automatically offload WordPress media files to Bunny Storage, a high-performance and cost-effective
                cloud storage service with optimal latency, global replication, and maximum throughput. After setup,
                any new media you upload to WordPress will automatically be transferred to Bunny Storage.
            </p>
            <p class="bn-text-200-regular bn-mt-3">
                Offloaded media can be optionally accelerated with Bunny CDN by configuring a Pullzone on 
                <a href="https://dash.bunny.net" target="_blank">dash.bunny.net</a> to deliver content globally with low latency.
            </p>
        </div>
        <img src="<?php echo esc_attr($this->assetUrl('offloader-header.svg')) ?>" alt="">
    </section>
    <?php if ($showApiKeyAlert): ?>
        <div class="alert red bn-m-5">Could not connect to api.bunny.net. Please make sure the API key is correct.</div>
    <?php endif; ?>
    <?php if ($showCdnAccelerationAlert): ?>
    <div class="bn-m-5"><?php echo $this->renderPartialFile('cdn-acceleration.alert.php'); ?></div>
    <?php endif; ?>
    <div class="bn-px-5">
        <section class="bn-section statistics">
            <?php echo $this->renderPartialFile('offloader.statistics.php', ['attachments' => $attachments, 'config' => $config, 'attachmentsWithError' => 0]) ?>
        </section>
        <section class="bn-section bn-px-0 bn-section--no-divider">
            <h3 class="bn-section__title">Get Started with Bunny Offloader</h3>
            <p class="bn-text-200-regular bn-mb-3">To enable Bunny Offloader, run the Setup Wizard to create a Storage Zone and configure automatic media offloading.</p>
            <ol class="bn-ml-4 bn-mb-4">
                <li class="bn-mb-2">The wizard will create a new Bunny Storage Zone for your media files</li>
                <li class="bn-mb-2">Configure optional replication regions for global redundancy</li>
                <li class="bn-mb-2">Choose whether to offload existing media or only new uploads</li>
            </ol>
            <p class="bn-text-200-regular bn-mb-4"><strong>Optional:</strong> After setup, you can configure a Pullzone on <a href="https://dash.bunny.net" target="_blank">dash.bunny.net</a> to deliver offloaded media via CDN for faster global delivery.</p>
            <a class="bunnycdn-button bunnycdn-button--primary" href="<?php echo esc_url(admin_url('admin.php?page=bunnycdn&section=wizard')) ?>">Run Setup Wizard</a>
        </section>
    </div>
    <?php echo wp_nonce_field('bunnycdn-save-cdn') ?>
</form>
