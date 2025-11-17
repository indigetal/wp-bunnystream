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
use Bunny\Wordpress\Api\Exception\NotFoundException;

class Overview implements ControllerInterface
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function run(bool $isAjax): void
    {
        if ($isAjax) {
            if (isset($_GET['perform']) && 'get-api-data' === $_GET['perform']) {
                $this->handleGetApiData();

                return;
            }
            wp_send_json_error(['message' => 'Invalid request'], 400);

            return;
        }
        wp_enqueue_script('echarts', $this->container->assetUrl('echarts.min.js'), ['jquery']);
        $this->container->getOffloaderUtils()->updateStoragePassword();
        $this->container->renderTemplateFile('overview.php', [], ['cssClass' => 'overview loading']);
    }

    private function handleGetApiData(): void
    {
        // Simplified Overview: Billing + basic Storage Zone info only
        // No Pullzone/CDN statistics required
        $api = $this->container->getApiClient();
        
        try {
            $billing = $api->getBilling();
        } catch (\Exception $e) {
            wp_send_json_error([
                'type' => 'error', 
                'message' => 'The Bunny API is currently unavailable. Please try again later.'.\PHP_EOL.\PHP_EOL.'Details: '.$e->getMessage()
            ]);
            return;
        }

        // Storage Zone basic info (if configured for offloading)
        $storageZoneId = $this->container->getOffloaderConfig()->getStoragezoneId();
        $storageName = 'Not configured';
        
        if (null !== $storageZoneId && $storageZoneId > 0) {
            try {
                $storageDetails = $api->getStorageZone($storageZoneId);
                $storageName = $storageDetails->getName();
            } catch (\Exception $e) {
                $storageName = 'Error loading storage zone';
            }
        }

        // Simple response: just billing balance and storage zone name
        // Note: Detailed bandwidth/cache/request statistics removed (were Pullzone-specific)
        wp_send_json_success([
            'overview' => [
                'billing' => [
                    'balance' => $billing->getBalanceHumanReadable()
                ],
                'storage' => [
                    'name' => $storageName,
                    'id' => $storageZoneId
                ]
            ]
        ]);
    }

}
