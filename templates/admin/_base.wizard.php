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
 * @var int $step
 */
?>
<div id="bunnycdn-admin-wrapper">
    <main>
        <header>
            <img src="<?php echo esc_attr($this->assetUrl('bunny-logo-dark.svg')) ?>" alt="bunny.net logo" width="150" height="43">
            <div class="user-profile loading">
                <div class="details">
                    <a data-field="email" target="_blank" href="https://dash.bunny.net/account/settings">&nbsp;</a>
                    <span data-field="name">&nbsp;</span>
                </div>
                <img src='data:image/svg+xml;charset=utf-8,<svg width="60" height="60" viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="0" y="0" width="60" height="60" fill="%23dcdcde"/></svg>' alt="user profile" width="60" height="60">
            </div>
        </header>
        <article class="<?php echo esc_attr($cssClass) ?>">
            <div class="container divider no-nav bn-p-0">
                <nav>
                    <ul>
                        <li class="<?php echo 1 === $step ? 'active' : '' ?> <?php echo $step > 1 ? 'completed' : '' ?>">
                            <span class="circle">1</span>
                            Step 1
                        </li>
                        <li class="divider"></li>
                        <li class="<?php echo 2 === $step ? 'active' : '' ?> <?php echo $step > 2 ? 'completed' : '' ?>">
                            <span class="circle">2</span>
                            Step 2
                        </li>
                        <li class="divider"></li>
                        <li class="<?php echo 3 === $step ? 'active' : '' ?> <?php echo $step > 3 ? 'completed' : '' ?>">
                            <span class="circle">3</span>
                            Step 3
                        </li>
                    </ul>
                </nav>
                <?php echo $contentsHtml ?>
            </div>
        </article>
        <footer>
            <address>bunny.net WP Plugin - Version <?php echo esc_html($this->getVersion()) ?></address>
        </footer>
    </main>
</div>
