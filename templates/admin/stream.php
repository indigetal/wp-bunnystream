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

use Bunny\Wordpress\Config\Stream as StreamConfig;

// Don't load directly.
if (!defined('ABSPATH')) {
    exit('-1');
}

/**
 * @var \Bunny\Wordpress\Admin\Container $this
 * @var bool $apiAvailable
 * @var \Bunny\Wordpress\Config\Stream $config
 * @var bool $showSuccess
 * @var \Bunny\Wordpress\Api\Stream\Library[] $libraries
 */
?>
<form method="POST" class="container bg-gradient bn-p-0" autocomplete="off" id="stream-config">
    <section class="bn-section bn-section-hero">
        <div>
            <h1>Stream</h1>
            <p>Deliver videos without buffering to your customers through our global network. Automatically transcode and serve the best option to your viewers, without even leaving your WordPress admin dashboard.</p>
            <a href="https://bunny.net/stream/" target="_blank" class="bn-link bn-link--external">More Information</a>
        </div>
        <img src="<?php echo esc_attr($this->assetUrl('stream-header.svg')) ?>" alt="">
    </section>
    <div class="bn-px-5">
        <?php if (true === $showSuccess): ?>
            <div class="alert green bn-mt-4 bn-mt-0">
                The configuration was saved.
            </div>
        <?php endif; ?>

        <section class="bn-section bn-px-0 bn-pt-0 hide-disabled bn-section--no-divider">
            <ul class="bn-m-0">
                <li class="bn-section bn-px-0 bn-section--split">
                    <label class="bn-section__title" for="stream-libraries">Allowed libraries</label>
                    <div class="bn-section__content">
                        <?php if ($apiAvailable): ?>
                            <div>
                                <input type="checkbox" name="stream[libraries_all]" id="stream-libraries-all" class="bunnycdn-toggle" value="1" <?php echo $config->isLibrariesAll() ? 'checked' : '' ?>>
                                <label for="stream-libraries-all" class="bn-text-200-regular">Allow all libraries</label>
                            </div>
                            <select class="bn-mt-2 bn-p-2 stream-hide-libraries-all <?php echo $config->isLibrariesAll() ? 'bn-d-none' : '' ?>" id="stream-libraries" name="stream[libraries][]" multiple>
                                <?php foreach ($libraries as $library): ?>
                                <option value="<?php echo esc_attr($library->getId()) ?>" <?php echo in_array($library->getId(), $config->getLibraries(), true) ? 'selected' : '' ?>><?php echo esc_html($library->getName()) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <a href="#TB_inline?&width=630&height=440&inlineId=stream-library-create-modal" class="thickbox bunnycdn-button bunnycdn-button--primary bunnycdn-button--lg bn-mt-3" name="Create a new Stream library">Create a new library</a>
                            <p class="bn-text-100-regular">These libraries will be shown as options in the <em>bunny.net Stream Video</em> block.</p>
                        <?php else: ?>
                            <div class="alert compact red">This configuration cannot be changed because the Bunny API is not available.</div>
                        <?php endif; ?>
                    </div>
                </li>
                <li class="bn-section bn-px-0 bn-section--split">
                    <label class="bn-section__title" for="stream-allow-uploads">Allow uploads</label>
                    <div class="bn-section__content">
                        <input type="checkbox" name="stream[allow_uploads]" id="stream-allow-uploads" class="bunnycdn-toggle" value="1" <?php echo $config->isAllowUploads() ? 'checked' : '' ?>>
                        <label for="stream-allow-uploads" class="bn-text-200-regular">Allow editors to upload videos</label>
                        <p class="bn-pt-2">Allows users with the <a href="https://wordpress.org/documentation/article/roles-and-capabilities/#upload_files" target="_blank"><code>upload_files</code></a> capability to upload videos to an allowed library. Administrators can still upload videos when this option is disabled.</p>
                    </div>
                </li>
                <li class="bn-section bn-px-0 bn-section--split">
                    <label class="bn-section__title" for="stream-allow-uploads">Player settings</label>
                    <div class="bn-section__content">
                        <p>The player settings can be customized at <a href="https://dash.bunny.net/stream" target="_blank">dash.bunny.net</a>.</p>
                    </div>
                </li>
            </ul>
        </section>
        <input type="submit" value="Save Settings" class="bunnycdn-button bunnycdn-button--primary bunnycdn-button--lg">
    </div>
    <?php echo wp_nonce_field('bunnycdn-save-stream') ?>
    <?php if (!$apiAvailable): ?>
    <input type="hidden" name="stream[api_unavailable]" value="1">
    <?php endif; ?>
</form>

<?php if ($apiAvailable): ?>
<div id="stream-library-create-modal" style="display:none;">
    <form id="stream-library-create" autocomplete="off">
        <div class="row">
            <label for="stream-library-create-name">Name</label>
            <div class="column">
                <input type="text" placeholder="Name" class="bunnynet-input" data-field="name" id="stream-library-create-name" />
            </div>
        </div>
        <div class="row">
            <label for="stream-library-create-primary-region">Primary region</label>
            <div class="column">
                <input type="text" class="bunnynet-input" value="Europe (Frankfurt)" id="stream-library-create-primary-region" disabled>
                <p>The primary region for your data where file uploads will be performed.</p>
            </div>
        </div>
        <div class="row">
            <label for="stream-library-create-replication-regions">Replication regions</label>
            <div class="column">
                <ul data-field="replication_regions" class="replication">
                    <?php foreach (StreamConfig::STORAGE_REPLICATION_REGIONS as $key => $label): ?>
                        <li>
                            <input type="checkbox" value="<?php echo esc_attr($key) ?>" id="stream-library-create-replication-<?php echo esc_attr($key) ?>" class="bunnycdn-toggle">
                            <label for="stream-library-create-replication-<?php echo esc_attr($key) ?>" class="bn-text-200-regular"><?php echo esc_html($label) ?></label>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p>If any selected, your data will automatically be replicated into the selected regions and delivered from the closest region to the CDN PoP.</p>
                <p>You can add more regions later, but you can't remove them after the Storage Zone is created.</p>
            </div>
        </div>
        <div class="row">
            <label for="stream-library-create-price">Price</label>
            <p>$<span id="stream-library-create-price">0.01</span>/GB/month</p>
        </div>
        <div class="row">
            <label for="stream-library-create-button"></label>
            <div class="create">
                <button type="button" class="bunnycdn-button bunnycdn-button--primary bunnycdn-button--lg">Create library</button>
                <div class="loading hide">
                    <div class="icon"></div>
                    <div>Creating library</div>
                </div>
                <div class="bunnycdn-alert compact bn-d-none"></div>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<script type="text/javascript">
    jQuery(document).ready(function() {
        new SlimSelect({ select: '#stream-libraries', settings: { placeholderText: 'Select libraries' } });
    });
</script>
