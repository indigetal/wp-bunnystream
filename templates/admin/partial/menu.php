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
 * @var array<string, string> $items
 * @var string $current
 * @var string $cssClass
 */

/**
 * @param string $section
 *
 * @return string
 */
$getUrlSafe = function (string $section): string {
    return add_query_arg([
        'page' => 'bunnycdn',
        'section' => $section,
    ], admin_url('admin.php'));
};

?>
<ul class="<?php echo esc_attr($cssClass) ?>">
    <?php foreach ($items as $section => $label): ?>
        <li <?php echo $current === $section ? 'class="active"' : '' ?>><a href="<?php echo $getUrlSafe($section) ?>"><?php echo esc_html($label) ?></a></li>
    <?php endforeach; ?>
</ul>
