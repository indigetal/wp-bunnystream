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

use Bunny\Storage\Client as StorageClient;
use Bunny\Wordpress\Service\AttachmentMover;

class Attachment
{
    private StorageClient $storage;

    public function __construct(StorageClient $storage)
    {
        $this->storage = $storage;
    }

    public function run(): void
    {
        if (!is_admin()) {
            return;
        }
        $location = sanitize_key($_GET['location'] ?? '');
        $id = (int) sanitize_key($_GET['id'] ?? 0);
        if (0 === $id || '' === $location) {
            wp_die(esc_html__('The requested attachment could not be loaded.', 'bunnycdn'), 404);
            exit;
        }
        $type = get_post_type($id);
        if ('attachment' !== $type) {
            wp_die(esc_html__('The requested ID is not an attachment.', 'bunnycdn'), 400);
            exit;
        }
        $file = get_attached_file($id);
        if (empty($file)) {
            wp_die(esc_html__('The requested attachment could not be loaded.', 'bunnycdn'), 404);
            exit;
        }
        try {
            if (AttachmentMover::LOCATION_ORIGIN === $location) {
                $this->renderFromOrigin($file);

                return;
            }
            if (AttachmentMover::LOCATION_STORAGE === $location) {
                $this->renderFromStorage($file);

                return;
            }
            wp_die(esc_html__('Invalid location parameter', 'bunnycdn'));
        } catch (\Exception $e) {
            wp_die(esc_html(sprintf(
                /* translators: 1: PHP exception, not translated */
                __('Could not render the attachment: %1$s', 'bunnycdn'),
                $e->getMessage()
            )), 500);
        }
    }

    private function renderFromOrigin(string $file): void
    {
        $path = realpath($file);
        if (false === $path || !file_exists($path)) {
            throw new \Exception('File "'.$file.'" not found.');
        }
        header('Cache-Control: private');
        header('Content-Type: '.$this->getContentTypeFromPath($path));
        echo file_get_contents($path);
        exit;
    }

    private function renderFromStorage(string $file): void
    {
        $path = bunnycdn_offloader_remote_path($file);
        $contentsRaw = $this->storage->getContents($path);
        if (0 === strlen($contentsRaw)) {
            throw new \Exception('Invalid contents for file "'.$path.'"');
        }
        header('Cache-Control: private');
        header('Content-Type: '.$this->getContentTypeFromPath($path));
        echo $contentsRaw;
        // @noEscape
        exit;
    }

    private function getContentTypeFromPath(string $path): string
    {
        $result = wp_check_filetype($path);
        if (empty($result['type'])) {
            $result['type'] = 'image/jpeg';
        }

        return $result['type'];
    }
}
