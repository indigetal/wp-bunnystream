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

use Bunny\Wordpress\Service\AttachmentCounter;

// Don't load directly.
if (!defined('ABSPATH')) {
    exit('-1');
}

$titles = [
    AttachmentCounter::LOCAL => 'Synchronization will progress in background. The files will continue to be moved, even if you close this page.',
    AttachmentCounter::BUNNY => 'Attachments offloaded to Bunny Storage.',
    AttachmentCounter::EXCLUDED => 'Attachments excluded by the current configuration. They will not be moved to Bunny Storage.',
];

/**
 * @var \Bunny\Wordpress\Admin\Container $this
 * @var array<string, int> $attachments
 * @var int $attachmentsWithError
 * @var \Bunny\Wordpress\Config\Offloader $config
 */
?>
<h2 class="bn-section__title bn-mb-4">Statistics</h2>
<ul class="statistics">
    <?php foreach ($attachments as $label => $count): ?>
        <li data-label="<?php echo esc_attr($label) ?>" class="<?php echo AttachmentCounter::EXCLUDED === $label && 0 === $count ? 'hidden' : ''; ?>">
            <span class="label"><?php echo esc_html($label) ?></span>
            <div class="count" title="<?php echo esc_html($titles[$label]) ?>">
                <span class="count"><?php echo esc_html($count) ?></span>
                <?php if (AttachmentCounter::EXCLUDED === $label): ?>
                    <span class="info"></span>
                <?php elseif (AttachmentCounter::LOCAL === $label && $config->isEnabled() && $config->isSyncExisting()): ?>
                    <?php if ($count > $attachmentsWithError): ?>
                        <span class="loading"></span>
                    <?php elseif ($attachmentsWithError > 0): ?>
                        <span class="error" title="We didn't manage to offload some attachments. Please check your server logs or contact Bunny Support for help."></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <span class="unit">attachments</span>
        </li>
    <?php endforeach; ?>
</ul>
