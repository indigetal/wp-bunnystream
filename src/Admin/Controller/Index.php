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
use Bunny\Wordpress\Api\Config as ApiConfig;
use Bunny\Wordpress\Api\Exception\AuthorizationException;

class Index implements ControllerInterface
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function run(bool $isAjax): void
    {
        if ('1' === get_option('bunnycdn_wizard_finished')) {
            $mode = get_option('bunnycdn_wizard_mode', 'standalone');
            if ('agency' === $mode) {
                // Agency mode: redirect to Offloader (CDN section removed)
                $_GET['section'] = 'offloader';
                $this->container->newController(Offloader::class)->run($isAjax);
            } else {
                // Standalone mode: redirect to Overview (billing/bandwidth)
                $_GET['section'] = 'overview';
                $this->container->newController(Overview::class)->run($isAjax);
            }

            return;
        }
        if (isset($_GET['apiKey'])) {
            $token = $this->container->sanitizeApiKey($_GET['apiKey']);
            $api = $this->container->newApiClient(new ApiConfig($token));
            try {
                $user = $api->getUser();
            } catch (AuthorizationException $e) {
                $this->container->renderTemplateFile('index.error.php', ['error' => 'Error obtaining data from the API: Invalid API key'], ['cssClass' => 'index'], '_base.index.php');

                return;
            } catch (\Exception $e) {
                trigger_error('bunnycdn: error validating API key: '.$e->getMessage(), \E_USER_WARNING);
                $this->container->renderTemplateFile('index.error.php', ['error' => 'Error obtaining data from the API: '.$e->getMessage()], ['cssClass' => 'index'], '_base.index.php');

                return;
            }
            update_option('bunnycdn_api_key', $token);
            update_option('bunnycdn_api_user', $user);
            $this->container->redirectToSection('wizard');

            return;
        }
        if (!$isAjax && false !== get_option('bunny-offload')) {
            $url = add_query_arg(['s' => 'bunnycdn'], admin_url('plugins.php'));
            $message = '<p>We detected settings for a previous version of the bunny.net plugin. Please <a href="%s">re-activate the plugin</a> if you wish to upgrade the settings to the newer version.</p>';
            bunny_polyfill_wp_admin_notice(sprintf($message, $url), ['type' => 'error']);
        }
        $domain = site_url();
        if (preg_match('#^https?://(?<host>[^/]+)/?.*$#', $domain, $matches) && !empty($matches['host'])) {
            $domain = (is_ssl() ? 'https' : 'http').'://'.$matches['host'];
        }
        $callbackUrl = $this->container->getSectionUrl('index');
        $loginUrlSafe = 'https://dash.bunny.net/auth/login?source=wp-plugin&domain='.$domain.'&callbackUrl='.urlencode($callbackUrl);
        $registerUrlSafe = 'https://dash.bunny.net/auth/register?source=wp-plugin&domain='.$domain.'&callbackUrl='.urlencode($callbackUrl);
        $this->container->renderTemplateFile('index.php', ['registerUrlSafe' => $registerUrlSafe, 'loginUrlSafe' => $loginUrlSafe], ['cssClass' => 'index'], '_base.index.php');
    }
}
