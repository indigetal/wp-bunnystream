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
use Bunny\Wordpress\Service\AttachmentCounter;

class Reset implements ControllerInterface
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function run(bool $isAjax): void
    {
        $attachmentCount = $this->container->getAttachmentCounter()->count();
        $canReset = 0 === $attachmentCount[AttachmentCounter::BUNNY];
        $error = null;
        $successMessage = null;
        $isAgencyMode = $this->container->getCdnConfig()->isAgencyMode();
        if (!empty($_POST['reset']) && 'yes' === $_POST['reset']) {
            check_admin_referer('bunnycdn-save-reset');
            if ($canReset && isset($_POST['reset_confirmed']) && '1' === $_POST['reset_confirmed']) {
                \Bunny\Wordpress\Config\Reset::all();
                $this->container->redirectToSection('index');

                return;
            }
            if ($canReset) {
                $error = 'You must acknowledge the changes to reset this plugin.';
            } else {
                $error = 'You cannot reset the plugin while the Content Offloading feature is in use.';
            }
        }
        if (!empty($_POST['convert_agency_mode']) && 'yes' === $_POST['convert_agency_mode']) {
            check_admin_referer('bunnycdn-save-reset');
            if ($canReset && isset($_POST['convert_agency_mode_confirmed']) && '1' === $_POST['convert_agency_mode_confirmed']) {
                \Bunny\Wordpress\Config\Reset::convertToAgencyMode();
                $this->container->redirectToSection('index');

                return;
            }
            if ($canReset) {
                $error = 'You must acknowledge the changes to convert to Agency Mode.';
            } else {
                $error = 'You cannot convert to Agency Mode while the Content Offloading feature is in use.';
            }
        }
        if (!empty($_POST['reset_stream_token_authentication']) && 'yes' === $_POST['reset_stream_token_authentication']) {
            check_admin_referer('bunnycdn-save-reset');
            \Bunny\Wordpress\Config\Reset::clearTokenAuthenticationCachedKeys();
            $successMessage = 'The Token Authentication cache was cleared.';
        }
        $formUrl = $this->container->getSectionUrl('reset', ['noheader' => 1]);
        $this->container->renderTemplateFile('reset.php', ['canReset' => $canReset, 'formUrl' => $formUrl, 'error' => $error, 'successMessage' => $successMessage, 'isAgencyMode' => $isAgencyMode], ['cssClass' => 'reset']);
    }
}
