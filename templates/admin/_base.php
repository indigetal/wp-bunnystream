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
 * @var string $cssClass
 * @var string $contentsHtml
 * @var string $mode
 */
$menuPrimary = [
    'overview' => __('Overview', 'bunnycdn'),
    'cdn' => 'CDN',
    'offloader' => 'Offloader',
    'optimizer' => 'Optimizer',
    'stream' => 'Stream',
    'fonts' => 'Fonts',
    'about' => __('About', 'bunnycdn'),
];

$isAgencyMode = 'agency' === $mode;
if ($isAgencyMode) {
    unset($menuPrimary['overview']);
    unset($menuPrimary['offloader']);
    unset($menuPrimary['optimizer']);
    unset($menuPrimary['stream']);
}

$menuSecondary = [
    'reset' => __('Reset', 'bunnycdn'),
];

?>
<div id="bunnycdn-admin-wrapper">
    <main>
        <header>
            <img src="<?php echo esc_attr($this->assetUrl('bunny-logo-dark.svg')) ?>" alt="bunny.net logo" width="150" height="43">
            <?php if (false === $isAgencyMode): ?>
            <div class="user-profile loading">
                <div class="details">
                    <a data-field="email" target="_blank" href="https://dash.bunny.net/account/settings">&nbsp;</a>
                    <span data-field="name">&nbsp;</span>
                </div>
                <img src='data:image/svg+xml;charset=utf-8,<svg width="60" height="60" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="60" height="60" fill="%23dcdcde"/></svg>' alt="user profile" width="60" height="60">
                <div class="alert gray bn-d-none" id="user-profile-alert"></div>
            </div>
            <?php endif; ?>
        </header>
        <nav>
            <?php echo $this->renderMenuHtml($menuPrimary, 'main') ?>
            <?php echo $this->renderMenuHtml($menuSecondary, 'secondary') ?>
        </nav>
        <article class="<?php echo esc_attr($cssClass) ?>">
            <?php echo $contentsHtml ?>
        </article>
        <footer>
            <address>bunny.net WP Plugin - Version <?php echo esc_html($this->getVersion()) ?></address>
        </footer>
    </main>
</div>
