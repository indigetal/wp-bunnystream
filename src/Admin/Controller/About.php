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

namespace Bunny\Wordpress\Admin\Controller;

use Bunny\Wordpress\Admin\Container;

class About implements ControllerInterface
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function run(bool $isAjax): void
    {
        global $wp_version;
        $serverVars = $this->container->getSanitizedServerVars();
        $headerValues = ['Cdn-Requestid' => $serverVars['HTTP_CDN_REQUESTID'], 'Via' => $serverVars['HTTP_VIA']];
        $headers = [];
        foreach ($headerValues as $key => $value) {
            $headers[] = $key.': '.(empty($value) ? '<em>undefined</em>' : '<code>'.esc_html($value).'</code>');
        }
        $info = ['Plugin version' => esc_html(BUNNY_OFFLOAD_VERSION), 'WordPress version' => esc_html($wp_version), 'Request headers' => implode('<br />', $headers), 'CDN acceleration override' => defined('BUNNYCDN_FORCE_ACCELERATED') ? $this->getHtmlTyped(BUNNYCDN_FORCE_ACCELERATED) : '<em>not set</em>'];
        if ($this->container->getOffloaderConfig()->isConfigured()) {
            $info['WP Upload Directory'] = esc_html(wp_get_upload_dir()['basedir']);
            $info['Offloader base path'] = esc_html(bunnycdn_offloader_remote_path(wp_get_upload_dir()['basedir'].''));
            $info['WP ABSPATH'] = defined('ABSPATH') ? esc_html(ABSPATH) : '<em>not set</em>';
            $info['WP WP_CONTENT_DIR'] = defined('WP_CONTENT_DIR') ? esc_html(WP_CONTENT_DIR) : '<em>not set</em>';
            $info['WP WP_CONTENT_URL'] = defined('WP_CONTENT_URL') ? esc_html(WP_CONTENT_URL) : '<em>not set</em>';
            $info['WP UPLOADS'] = defined('UPLOADS') ? esc_html(UPLOADS) : '<em>not set</em>';
            $lastSync = (int) get_option('_bunnycdn_offloader_last_sync');
            $info['Offloader last sync'] = 0 === $lastSync ? '<em>never</em>' : esc_html(date(\DATE_RFC3339, $lastSync));
        }
        $this->container->renderTemplateFile('about.php', ['debugInformationHtml' => $info], ['cssClass' => 'about']);
    }

    /**
     * @param mixed $value
     */
    private function getHtmlTyped($value): string
    {
        $type = gettype($value);
        if ('boolean' === $type) {
            $value = true === $value ? 'true' : 'false';
        }

        return '<em>'.$type.'</em> '.esc_html($value);
    }
}
