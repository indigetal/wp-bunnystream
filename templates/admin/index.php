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
 * @var string $registerUrlSafe
 * @var string $loginUrlSafe
 */
?>
<div class="container no-nav bn-p-0">
    <section class="bn-section bg-gradient-reverse welcome">
        <img src="<?php echo esc_attr($this->assetUrl('homepage-welcome.png')) ?>" alt="">
        <h2>Welcome to <strong>Bunny Offload Media Library</strong></h2>
        <p class="bn-mt-3 bn-text-200-regular">Automatically offload WordPress media to Bunny.net cloud storage and stream video with seamless Media Library integration.</p>
        <a href="<?php echo $registerUrlSafe ?>" target="_blank" class="bunnycdn-button bunnycdn-button--primary bunnycdn-button--xxl">Create An Account</a>
        <p>Already have an account? <a href="<?php echo $loginUrlSafe ?>">Log in</a>.</p>
    </section>
    <section class="bn-section subtext bn-py-7 bn-px-6">
        <p>Set up media offloading in under <strong>5 minutes</strong>.</p>
    </section>
    <section class="bn-section columns-2">
        <div class="bn-text-center">
            <img src="<?php echo esc_attr($this->assetUrl('homepage-offloader.svg')) ?>" alt="">
        </div>
        <div>
            <h3>Bunny Offloader</h3>
            <h4>Automatic Media Offloading to Cloud Storage</h4>
            <p>
                Automatically offload WordPress media files (images, videos, documents) to Bunny Storage, a high-performance 
                and cost-effective cloud storage service. Any new media you upload to WordPress will be automatically 
                transferred to Bunny Storage with optional global replication for redundancy.
            </p>
            <p class="bn-mt-2">
                Optionally configure a Pullzone on <a href="https://dash.bunny.net" target="_blank">dash.bunny.net</a> 
                to deliver offloaded media via CDN for faster global delivery.
            </p>
        </div>
    </section>
    <section class="bn-section columns-2">
        <div class="bn-text-center">
            <img src="<?php echo esc_attr($this->assetUrl('homepage-stream.svg')) ?>" alt="">
        </div>
        <div>
            <h3>Bunny Stream</h3>
            <h4>Professional Video Hosting & Delivery</h4>
            <p>
                Embed and deliver video content with exceptional performance powered by Bunny Stream. Upload videos 
                directly from WordPress Media Library and embed them with the Bunny Stream video block for seamless 
                integration with your content.
            </p>
        </div>
    </section>
</div>
