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
 * @var array<string, string> $debugInformationHtml
 */
?>
<div class="banner"></div>
<div class="container">
    <section class="bn-section bn-p-0">
        <div class="bn-section__title">About Bunny Offload Media Library</div>
        <p class="bn-pt-2 bn-pb-4"><strong>Bunny Offload Media Library</strong> automatically offloads WordPress media (videos, images, documents) to <a href="https://bunny.net/" target="_blank">bunny.net</a> cloud storage. This plugin focuses exclusively on media offloading using Bunny Storage and Bunny Stream, providing seamless integration with WordPress Media Library.</p>
        <p class="bn-pb-4">For CDN acceleration and advanced optimization features, configure Pullzones and settings directly on <a href="https://dash.bunny.net" target="_blank">dash.bunny.net</a> dashboard for greater control and flexibility.</p>
    </section>
    <section class="bn-section bn-px-0 bn-is-max-width">
        <div class="bn-section__title">Bunny.net Services Used by This Plugin</div>
        <section class="bn-block bn-mt-5">
            <div class="bn-block__title">Bunny Storage</div>
            <p>This plugin automatically offloads your WordPress media files to Bunny Storage Edge Tier SSD. Simple pricing with no egress costs, commitments, or minimums at <strong>$0.02/GB/Region</strong>.</p>
            <a href="https://bunny.net/pricing/storage/" target="_blank" class="bn-link bn-link--blue bn-link--chain">Learn more about Storage Pricing</a>
        </section>
        <section class="bn-block bn-mt-5">
            <div class="bn-block__title">Bunny Stream</div>
            <p>Embed and deliver video content with exceptional performance and reliability powered by a state-of-the-art video delivery system. Storage and streaming bandwidth costs apply.</p>
            <a href="https://bunny.net/pricing/stream/" target="_blank" class="bn-link bn-link--blue bn-link--chain">Learn more about Stream Pricing</a>
        </section>
        <section class="bn-block bn-mt-5">
            <div class="bn-block__title">Bunny CDN (Optional)</div>
            <p>Accelerate media delivery with CDN. Configure Pullzones on <a href="https://dash.bunny.net" target="_blank">dash.bunny.net</a> to cache and deliver offloaded media globally. Pay-as-you-go pricing starting at <strong>$1 per month</strong>.</p>
            <a href="https://bunny.net/pricing/cdn/" target="_blank" class="bn-link bn-link--blue bn-link--chain">Learn more about CDN Pricing</a>
        </section>
    </section>
    <section class="bn-section bn-section--no-divider bn-px-0 bn-pb-0">
        <div class="bn-section__title">Technical information</div>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Value</Th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($debugInformationHtml as $keySafe => $valueSafe): ?>
                <tr>
                    <td><?php echo $keySafe ?></td>
                    <td><?php echo $valueSafe ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>
