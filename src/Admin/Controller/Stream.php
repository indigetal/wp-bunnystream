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
use Bunny\Wordpress\Config\Stream as StreamConfig;

class Stream implements ControllerInterface
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function run(bool $isAjax): void
    {
        $cdnConfig = $this->container->getCdnConfig();
        if ($cdnConfig->isAgencyMode()) {
            $this->container->renderTemplateFile('error.api-unavailable.php', ['error' => 'There is no API key configured.']);

            return;
        }
        $showSuccess = false;
        $apiAvailable = false;
        if (!empty($_POST)) {
            check_admin_referer('bunnycdn-save-stream');
            if (isset($_POST['perform']) && 'library-create' === $_POST['perform']) {
                $name = $_POST['name'] ?? '';
                $replicationRegions = $_POST['replication_regions'] ?? [];
                foreach ($replicationRegions as $replicationRegion) {
                    if (empty($replicationRegion) || !isset(StreamConfig::STORAGE_REPLICATION_REGIONS[$replicationRegion])) {
                        throw new \Exception('Invalid replication region: '.$replicationRegion);
                    }
                }
                try {
                    $library = $this->container->getApiClient()->createStreamLibrary($name, $replicationRegions);
                    wp_send_json_success(['id' => $library->getId(), 'name' => $library->getName()]);
                } catch (\Exception $e) {
                    wp_send_json_error(['message' => $e->getMessage()]);
                }

                return;
            }
            $this->container->getStreamConfig()->handlePost($_POST['stream'] ?? []);
            $this->container->getStreamConfig()->saveToWpOptions();
            $showSuccess = true;
        }
        $libraries = [];
        try {
            $libraries = $this->container->getApiClient()->getStreamLibraries();
            $apiAvailable = true;
        } catch (\Exception $e) {
            // noop
        }
        $this->container->renderTemplateFile('stream.php', ['apiAvailable' => $apiAvailable, 'config' => $this->container->getStreamConfig(), 'showSuccess' => $showSuccess, 'libraries' => $libraries], ['cssClass' => 'stream']);
    }
}
