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
 * @var string $formUrlSafe
 * @var string $url
 * @var string $backUrlSafe
 * @var string $mode
 * @var string|null $error
 */
?>
<form method="POST" action="<?php echo $formUrlSafe ?>" class="container bg-transparent" autocomplete="off">
    <section class="bn-section">
        <div class="bn-section__title bn-m-0">Configure your website details</div>
    </section>
    <section class="bn-section">
        <?php if (null !== $error): ?>
            <div class="alert red">
                <?php echo esc_html($error) ?>
            </div>
        <?php endif; ?>
        <div>
            <label class="bn-color-bunny-dark" for="website-url">Website URL:</label>
            <input type="text" class="bunnycdn-input bn-mt-2" id="website-url" name="url" value="<?php echo esc_attr($url) ?>" readonly>
        </div>
        <p class="bn-py-3">Please confirm your website URL. This will be used to configure Storage Zone paths for media offloading. The default value was automatically configured based on your WordPress settings.</p>
        <p>You should only change this if your website is hosted on a different address than configured in the WordPress settings.</p>
        <div class="bn-mt-3">
            <input type="submit" value="Continue" class="bunnycdn-button bunnycdn-button--primary">
            <a href="<?php echo $backUrlSafe ?>" class="bunnycdn-button bunnycdn-button--secondary bn-ms-3">Go back</a>
        </div>
    </section>
    <input type="hidden" name="mode" value="<?php echo esc_attr($mode) ?>">
    <?php echo wp_nonce_field('bunnycdn-save-wizard-step2') ?>
</form>
