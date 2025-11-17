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
 * @var string $continueUrlSafe
 * @var string $agencyModeUrlSafe
 */
?>
<section class="bn-section">
    <div class="bn-section__title bn-m-0">Welcome to Bunny Offload Media Library!</div>
</section>
<section class="bn-section">
    <p class="bn-mb-3">The Setup Wizard guides you through a simple process of connecting your WordPress site to Bunny.net for automatic media offloading. Upload videos, images, and documents to Bunny Storage and Bunny Stream with seamless Media Library integration.</p>
    <p class="bn-mb-3">The wizard will guide you through 3 basic steps to get your media offloading up and running in just a few minutes.</p>
    <div class="alert blue">
        <strong>Agency Mode</strong> is designed for administrators who manage multiple WordPress sites. API keys will not be stored on this WordPress instance. You'll manage Offloader and Stream settings via <a href="https://dash.bunny.net" target="_blank" class="bn-link--white">dash.bunny.net</a>.
    </div>
    <div>
        <a href="<?php echo $continueUrlSafe ?>" class="bunnycdn-button bunnycdn-button--primary">Setup Wizard</a>
        <a href="<?php echo $agencyModeUrlSafe ?>" class="bunnycdn-button bunnycdn-button--secondary bn-ms-3">Agency Mode</a>
    </div>
</section>
