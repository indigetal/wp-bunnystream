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

namespace Bunny\Wordpress\Admin;

use Bunny\Wordpress\Admin\Controller\Attachment as AttachmentController;
use Bunny\Wordpress\Api\Client as ApiClient;
use Bunny\Wordpress\Api\Config as ApiConfig;
use Bunny\Wordpress\Config\Cdn as CdnConfig;
use Bunny\Wordpress\Config\Fonts as FontsConfig;
use Bunny\Wordpress\Config\Offloader as OffloaderConfig;
use Bunny\Wordpress\Config\Stream as StreamConfig;
use Bunny\Wordpress\Container as AppContainer;
use Bunny\Wordpress\Service\AttachmentCounter;
use Bunny\Wordpress\Service\AttachmentMover;
use Bunny\Wordpress\Service\CdnAcceleration;
use Bunny\Wordpress\Service\OffloaderSetup;
use Bunny\Wordpress\Utils\Offloader as OffloaderUtils;
use Bunny\Wordpress\Utils\Wizard as WizardUtils;

class Container
{
    private AppContainer $container;
    private string $baseUrl;
    private string $templatePath;

    public function __construct(AppContainer $container, string $baseUrl, string $templatePath)
    {
        $this->container = $container;
        $this->baseUrl = $baseUrl;
        $this->templatePath = $templatePath;
    }

    public function newApiClient(ApiConfig $config): ApiClient
    {
        return $this->container->newApiClient($config);
    }

    public function getApiClient(): ApiClient
    {
        return $this->container->getApiClient();
    }

    /**
     * @param class-string<Controller\ControllerInterface> $className
     */
    public function newController(string $className): Controller\ControllerInterface
    {
        return new $className($this);
    }

    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $baseVariables
     */
    public function renderTemplateFile(string $filename, array $variables = [], array $baseVariables = [], string $base = '_base.php'): void
    {
        $baseVariables['contentsHtml'] = $this->renderFile($filename, $variables);
        $baseVariables['mode'] = get_option('bunnycdn_wizard_mode', 'standalone');
        echo $this->renderFile($base, $baseVariables);
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function renderPartialFile(string $filename, array $variables = []): string
    {
        return $this->renderFile('partial/'.$filename, $variables);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderFile(string $filename, array $variables = []): string
    {
        ob_start();
        extract($variables);
        require sprintf('%s/%s', $this->templatePath, $filename);

        return ob_get_clean() ?: '';
    }

    /**
     * @param array<string, string> $items
     */
    public function renderMenuHtml(iterable $items, string $cssClass = ''): string
    {
        return $this->renderPartialFile('menu.php', ['items' => $items, 'current' => sanitize_key($_GET['section'] ?? ''), 'cssClass' => $cssClass]);
    }

    public function assetUrl(string $asset): string
    {
        return sprintf('%sassets/%s', $this->baseUrl, $asset);
    }

    public function getVersion(): string
    {
        return BUNNYCDN_WP_VERSION;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function redirectToSection(string $section, array $params = []): void
    {
        $url = $this->getSectionUrl($section, $params);
        if (headers_sent()) {
            echo $this->renderPartialFile('redirect.php', ['urlSafe' => $url]);
        } else {
            wp_redirect($url);
        }
    }

    public function getCdnAcceleration(): CdnAcceleration
    {
        static $instance;
        if (null !== $instance) {
            return $instance;
        }
        $instance = new CdnAcceleration($this->getApiClient(), $this->getSanitizedServerVars(), $this->getAttachmentCounter(), $this->getCdnConfig()->isAgencyMode(), $this->getOffloaderConfig()->isEnabled(), $this->getOffloaderConfig()->isConfigured(), $this->getCdnConfig()->getPullzoneId());

        return $instance;
    }

    public function newCdnAccelerationForWizard(bool $isAgencyMode): CdnAcceleration
    {
        return new CdnAcceleration($this->getApiClient(), $this->getSanitizedServerVars(), $this->getAttachmentCounter(), $isAgencyMode, false, false, null);
    }

    /**
     * @return array<string, string>
     */
    public function getSanitizedServerVars(): array
    {
        return ['HTTP_HOST' => $this->sanitizeHostname($_SERVER['HTTP_HOST']), 'HTTP_VIA' => preg_replace('#([^\\sa-zA-Z0-9-_/.]+)#', '', $_SERVER['HTTP_VIA'] ?? ''), 'HTTP_CDN_REQUESTID' => preg_replace('#([^a-zA-Z0-9-]+)#', '', $_SERVER['HTTP_CDN_REQUESTID'] ?? ''), 'REQUEST_METHOD' => preg_replace('#([^a-zA-Z]+)#', '', $_SERVER['REQUEST_METHOD'] ?? ''), 'REQUEST_SCHEME' => preg_replace('#([^a-z]+)#', '', $_SERVER['REQUEST_SCHEME'] ?? '')];
    }

    public function getCdnConfig(): CdnConfig
    {
        return $this->container->getCdnConfig();
    }

    public function getFontsConfig(): FontsConfig
    {
        return $this->container->getFontsConfig();
    }

    public function getOffloaderConfig(): OffloaderConfig
    {
        return $this->container->getOffloaderConfig();
    }

    public function reloadOffloaderConfig(): OffloaderConfig
    {
        return $this->container->reloadOffloaderConfig();
    }

    public function newOffloaderSetup(): OffloaderSetup
    {
        return new OffloaderSetup($this->getApiClient(), $this->getCdnAcceleration(), $this->getOffloaderUtils(), $this->getPathPrefix());
    }

    public function getAttachmentCounter(): AttachmentCounter
    {
        return $this->container->getAttachmentCounter();
    }

    public function getOffloaderUtils(): OffloaderUtils
    {
        return $this->container->getOffloaderUtils();
    }

    public function getWizardUtils(): WizardUtils
    {
        static $instance;
        if (null !== $instance) {
            return $instance;
        }
        $instance = new WizardUtils($this->getSanitizedServerVars());

        return $instance;
    }

    public function newAttachmentMover(): AttachmentMover
    {
        return $this->container->newAttachmentMover();
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getSectionUrl(string $section, array $params = []): string
    {
        $params['page'] = 'bunnycdn';
        $params['section'] = $section;
        if ('index' === $section) {
            unset($params['section']);
        }

        return add_query_arg($params, admin_url('admin.php'));
    }

    public function newAttachmentController(): AttachmentController
    {
        return new AttachmentController($this->container->getStorageClientFactory()->newWithConfig($this->container->getOffloaderConfig()));
    }

    public function getPathPrefix(): string
    {
        return $this->container->getPathPrefix();
    }

    public function sanitizeApiKey(string $apiKey): string
    {
        return (string) preg_replace('/([^a-zA-Z0-9_-]+)/', '', $apiKey);
    }

    public function sanitizeHostname(string $hostname): string
    {
        return (string) preg_replace('/([^a-z0-9-.]+)/', '', strtolower($hostname));
    }

    public function hasCustomDirectories(): bool
    {
        if (defined('UPLOADS') && UPLOADS !== 'uploads/') {
            return true;
        }
        if (defined('WP_CONTENT_DIR') && WP_CONTENT_DIR !== ABSPATH.'wp-content') {
            return true;
        }
        if (defined('WP_CONTENT_URL') && WP_CONTENT_URL !== get_option('siteurl').'/wp-content') {
            return true;
        }

        return false;
    }

    public function getStreamConfig(): StreamConfig
    {
        return $this->container->getStreamConfig();
    }
}
