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
use Bunny\Wordpress\Api\Exception\AuthorizationException;
use Bunny\Wordpress\Api\Exception\NotFoundException;
use Bunny\Wordpress\Config\Offloader as OffloaderConfig;
use Bunny\Wordpress\Service\AttachmentCounter;

class Offloader implements ControllerInterface
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function run(bool $isAjax): void
    {
        $errorMessage = null;
        $successMessage = null;
        $attachmentCount = $this->container->getAttachmentCounter()->count();
        if ($isAjax && isset($_GET['perform']) && 'get-statistics' === $_GET['perform']) {
            wp_send_json_success($attachmentCount);

            return;
        }
        if ($isAjax && isset($_GET['perform']) && 'get-sync-errors' === $_GET['perform']) {
            wp_send_json_success($this->container->getAttachmentCounter()->listFilesWithError());

            return;
        }
        if ($isAjax && isset($_POST['perform']) && 'resolve-conflict' === $_POST['perform']) {
            $id = (int) sanitize_key($_POST['attachment_id'] ?? 0);
            $keep = sanitize_key($_POST['keep'] ?? '');
            try {
                $this->container->newAttachmentMover()->resolveConflict($id, $keep);
                wp_send_json_success(['id' => $id]);
            } catch (\Exception $e) {
                wp_send_json_error(['id' => $id, 'message' => $e->getMessage()], 500);
            }

            return;
        }
        $offloaderConfig = $this->container->getOffloaderConfig();
        $showApiKeyAlert = false;
        
        try {
            $this->container->getOffloaderUtils()->updateStoragePassword();
        } catch (AuthorizationException $e) {
            $showApiKeyAlert = true;
        } catch (\Exception $e) {
            $errorMessage = 'The Bunny API is currently unavailable. Some configurations cannot be changed at the moment.'.\PHP_EOL.\PHP_EOL.'Details: '.$e->getMessage();
        }
        
        // Simplified: Remove CDN acceleration checks - focus on offloader only
        if (!$offloaderConfig->isConfigured() && $this->container->hasCustomDirectories()) {
            $this->container->renderTemplateFile('offloader.unsupported.php', [], ['cssClass' => 'offloader']);

            return;
        }
        if (!empty($_POST)) {
            check_admin_referer('bunnycdn-save-offloader');
            if (!$offloaderConfig->isConfigured()) {
                try {
                    $this->container->newOffloaderSetup()->perform($_POST['offloader'] ?? []);
                    $successMessage = 'The Content Offloader is now configured.';
                } catch (\Exception $e) {
                    $errorMessage = 'Error enabling the Content Offloader: '.$e->getMessage();
                }
                $offloaderConfig = $this->container->reloadOffloaderConfig();
            } else {
                $oldExcluded = $offloaderConfig->getExcluded();
                $wasEnabled = $offloaderConfig->isEnabled() && $offloaderConfig->isSyncExisting();
                $offloaderConfig->handlePost($_POST['offloader'] ?? []);
                $offloaderConfig->saveToWpOptions();
                if (!$wasEnabled && $offloaderConfig->isEnabled() && $offloaderConfig->isSyncExisting() && $attachmentCount[AttachmentCounter::LOCAL] > 0) {
                    try {
                        $pathPrefix = $this->container->getPathPrefix();
                        [$syncToken, $syncTokenHash] = $this->container->getOffloaderUtils()->generateSyncToken();
                        $this->container->getOffloaderUtils()->resetFileLocks();
                        $this->container->getOffloaderUtils()->resetFileAttempts();
                        $this->container->getOffloaderUtils()->resetFileErrors();
                        $this->container->getOffloaderUtils()->resetExclusions();
                        $this->container->getApiClient()->updateStorageZoneCron($offloaderConfig->getStorageZoneId(), $pathPrefix, $syncToken);
                        $offloaderConfig->saveSyncOptions($pathPrefix, $syncTokenHash);
                        $successMessage = 'The settings have been saved.';
                    } catch (\Exception $e) {
                        $errorMessage = 'api.bunny.net: could not update cronjob. Error: '.$e->getMessage();
                    }
                } else {
                    $successMessage = 'The settings have been saved.';
                }
                if ($oldExcluded !== $offloaderConfig->getExcluded()) {
                    $this->container->getOffloaderUtils()->resetExclusions();
                }
            }
        }
        // Simplified offloader configuration page - CDN features removed
        $showOffloaderSyncErrors = $offloaderConfig->isEnabled() && $offloaderConfig->isSyncExisting() && $this->container->getAttachmentCounter()->countWithError() > 0;
        $this->container->renderTemplateFile('offloader.config.php', [
            'attachments' => $attachmentCount, 
            'attachmentsWithError' => $this->container->getAttachmentCounter()->countWithError(), 
            'config' => $offloaderConfig, 
            'errorMessage' => $errorMessage, 
            'replicationRegions' => OffloaderConfig::STORAGE_REGIONS_SSD, 
            'showApiKeyAlert' => $showApiKeyAlert, 
            'showOffloaderSyncErrors' => $showOffloaderSyncErrors, 
            'successMessage' => $successMessage, 
            'viewOriginFileUrlTemplateSafe' => $this->container->getSectionUrl('attachment', ['location' => 'origin', 'id' => '{{id}}']), 
            'viewStorageFileUrlTemplateSafe' => $this->container->getSectionUrl('attachment', ['location' => 'storage', 'id' => '{{id}}'])
        ], ['cssClass' => 'offloader']);
    }
}
