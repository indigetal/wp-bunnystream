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
        // CDN acceleration alert removed - CDN features out of scope
        $this->container->renderTemplateFile('overview.php', [], ['cssClass' => 'overview loading']);
    }

    private function handleGetApiData(): void
    {
        // Simplified to Storage Zone metrics only (Pullzone statistics removed)
        $api = $this->container->getApiClient();
        
        try {
            $billing = $api->getBilling();
        } catch (\Exception $e) {
            wp_send_json_error(['type' => 'error', 'message' => 'The Bunny API is currently unavailable. Please try again later.'.\PHP_EOL.\PHP_EOL.'Details: '.$e->getMessage()]);

            return;
        }

        // Get Storage Zone bandwidth statistics (if configured)
        $storageZoneId = $this->container->getOffloaderConfig()->getStoragezoneId();
        $monthBandwidth = '0 B';
        $monthCharges = '$0.00';
        $bandwidthAvgCost = '$0.0000';
        
        if (null !== $storageZoneId) {
            try {
                $storageDetails = $api->getStorageZone($storageZoneId);
                // Note: Storage Zone API doesn't provide same detailed stats as Pullzone
                // Future enhancement: aggregate from Storage Zone usage data
            } catch (\Exception $e) {
                // Storage zone stats unavailable, use defaults
            }
        }

        // Simplified response - billing and basic bandwidth only
        // Cache and request statistics removed (CDN/Pullzone metrics)
        wp_send_json_success([
            'overview' => [
                'billing' => [
                    'balance' => $billing->getBalanceHumanReadable()
                ],
                'month' => [
                    'bandwidth' => $monthBandwidth,
                    'bandwidth_avg_cost' => $bandwidthAvgCost,
                    'charges' => $monthCharges
                ]
            ],
            'bandwidth' => [
                'total' => $monthBandwidth,
                'trend' => [
                    'value' => '0%',
                    'direction' => 'equal'
                ]
            ],
            'chart' => [
                'bandwidth' => []
            ]
        ]);
    }

}
