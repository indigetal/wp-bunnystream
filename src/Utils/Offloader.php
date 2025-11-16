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

namespace Bunny\Wordpress\Utils;

use Bunny\Storage\AuthenticationException;
use Bunny\Wordpress\Api\Client;
use Bunny\Wordpress\Api\Exception\AuthorizationException;
use Bunny\Wordpress\Api\Pullzone;
use Bunny\Wordpress\Config\Offloader as OffloaderConfig;
use Bunny\Wordpress\Service\AttachmentCounter;
use Bunny\Wordpress\Service\Exception\InvalidSQLQueryException;

class Offloader
{
    private const PASSWORD_CHECK_OPTION_KEY = '_bunnycdn_offloader_last_password_check';
    private const PASSWORD_CHECK_TEST_PATH = '/.wpbunny';
    private const PASSWORD_CHECK_THRESHOLD_SECONDS = 60 * 5;
    private const SYNC_DELAYED_THRESHOLD_SECONDS = 60 * 60 * 4;
    public const WP_POSTMETA_ATTEMPTS_KEY = '_bunnycdn_offload_attempts';
    public const WP_POSTMETA_ERROR = '_bunnycdn_offload_error';
    public const WP_POSTMETA_UPLOAD_LOCK_KEY = '_bunnycdn_upload_lock';
    public const WP_POSTMETA_KEY = '_bunnycdn_offloaded';
    public const WP_POSTMETA_EXCLUDED_KEY = '_bunnycdn_offloader_excluded';
    private Client $api;
    private AttachmentCounter $attachmentCounter;
    private OffloaderConfig $config;
    private \wpdb $db;
    private StorageClientFactory $storageClientFactory;

    public function __construct(Client $api, AttachmentCounter $attachmentCounter, OffloaderConfig $config, \wpdb $db, StorageClientFactory $storageClientFactory)
    {
        $this->api = $api;
        $this->attachmentCounter = $attachmentCounter;
        $this->config = $config;
        $this->db = $db;
        $this->storageClientFactory = $storageClientFactory;
    }

    /**
     * @return string[]
     */
    public function generateSyncToken(): array
    {
        $syncToken = bin2hex(random_bytes(32));
        $syncTokenHash = password_hash($syncToken, \PASSWORD_DEFAULT);

        return [$syncToken, $syncTokenHash];
    }

    public function shouldShowSyncDelayedMessage(): bool
    {
        $time = (int) get_option('_bunnycdn_offloader_last_sync');
        if (0 === $time) {
            return false;
        }
        if (!$this->config->isEnabled() || !$this->config->isSyncExisting()) {
            return false;
        }
        $count = $this->attachmentCounter->count();
        if (0 === $count[AttachmentCounter::LOCAL]) {
            return false;
        }

        return $time < time() - self::SYNC_DELAYED_THRESHOLD_SECONDS;
    }

    public function updateStoragePassword(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        $time = (int) get_option(self::PASSWORD_CHECK_OPTION_KEY);
        if (time() - $time < self::PASSWORD_CHECK_THRESHOLD_SECONDS) {
            return;
        }
        $storage = $this->storageClientFactory->new($this->config->getStorageZone(), $this->config->getStoragePassword());
        try {
            $storage->putContents(self::PASSWORD_CHECK_TEST_PATH, '');
            $storage->delete(self::PASSWORD_CHECK_TEST_PATH);
            update_option(self::PASSWORD_CHECK_OPTION_KEY, time());

            return;
        } catch (AuthenticationException $e) {
            // wrong password, get a new one
        } catch (\Exception $e) {
            // something else went wrong, noop
            return;
        }
        try {
            $storageZone = $this->api->getStorageZone($this->config->getStorageZoneId());
            $storage = $this->storageClientFactory->new($storageZone->getName(), $storageZone->getPassword());
            $storage->putContents(self::PASSWORD_CHECK_TEST_PATH, '');
            $storage->delete(self::PASSWORD_CHECK_TEST_PATH);
            update_option(self::PASSWORD_CHECK_OPTION_KEY, time());
            update_option('bunnycdn_offloader_storage_password', $storageZone->getPassword());

            return;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (AuthenticationException $e) {
            return;
        } catch (\Exception $e) {
            return;
        }
    }

    public function checkForExistingEdgeRule(Pullzone\Details $pullzone, string $pathPrefix): ?int
    {
        $storageId = null;
        foreach ($pullzone->getEdgerules() as $edgerule) {
            if (!$edgerule->isEnabled()) {
                continue;
            }
            if (!in_array($edgerule->getActionType(), [Pullzone\Edgerule::TYPE_ORIGIN_STORAGE, Pullzone\Edgerule::TYPE_ORIGIN_URL], true)) {
                continue;
            }
            foreach ($edgerule->getTriggers() as $trigger) {
                foreach ($trigger->getPatternMatches() as $pattern) {
                    if (Pullzone\EdgeruleTrigger::PATTERN_MATCH_NONE === $trigger->getPatternMatchingType()) {
                        continue;
                    }
                    if (parse_url($pattern, \PHP_URL_PATH) === $pathPrefix.'/wp-content/uploads/*') {
                        if (Pullzone\Edgerule::TYPE_ORIGIN_STORAGE !== $edgerule->getActionType()) {
                            throw new \Exception('The Pullzone associated with this website has Edge Rules that may conflict with the content offloading feature.');
                        }
                        $storageId = (int) $edgerule->getActionParameter1();
                        if (0 === $storageId) {
                            $storageId = null;
                            continue;
                        }
                        break 3;
                    }
                }
            }
        }

        return $storageId;
    }

    public function resetFileLocks(int $until = \PHP_INT_MAX): void
    {
        $sql = $this->db->prepare("\n            DELETE FROM {$this->db->postmeta}\n            WHERE post_id IN (SELECT ID FROM {$this->db->posts} WHERE post_type = %s)\n            AND meta_key = %s\n            AND meta_value < %d\n            ", 'attachment', self::WP_POSTMETA_UPLOAD_LOCK_KEY, $until);
        if (null === $sql) {
            throw new InvalidSQLQueryException();
        }
        $this->db->query($sql);
    }

    public function resetFileAttempts(): void
    {
        $sql = $this->db->prepare("\n            DELETE FROM {$this->db->postmeta}\n            WHERE post_id IN (SELECT ID FROM {$this->db->posts} WHERE post_type = %s)\n            AND meta_key = %s\n            ", 'attachment', self::WP_POSTMETA_ATTEMPTS_KEY);
        if (null === $sql) {
            throw new InvalidSQLQueryException();
        }
        $this->db->query($sql);
    }

    public function resetFileErrors(): void
    {
        $sql = $this->db->prepare("\n            DELETE FROM {$this->db->postmeta}\n            WHERE post_id IN (SELECT ID FROM {$this->db->posts} WHERE post_type = %s)\n            AND meta_key = %s\n            ", 'attachment', self::WP_POSTMETA_ERROR);
        if (null === $sql) {
            throw new InvalidSQLQueryException();
        }
        $this->db->query($sql);
    }

    public function resetExclusions(): void
    {
        $this->db->query('START TRANSACTION');
        // delete metadata for all items
        $sql = $this->db->prepare("\n            DELETE FROM {$this->db->postmeta}\n            WHERE post_id IN (SELECT ID FROM {$this->db->posts} WHERE post_type = %s)\n            AND meta_key = %s\n            ", 'attachment', self::WP_POSTMETA_EXCLUDED_KEY);
        if (null === $sql) {
            throw new InvalidSQLQueryException();
        }
        $this->db->query($sql);
        // get all attachments that were not offloaded yet
        /** @var string $sql */
        $sql = $this->db->prepare("\n                SELECT p.ID as id, pm1.meta_value AS path\n                FROM {$this->db->posts} p\n                INNER JOIN {$this->db->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = %s\n                LEFT JOIN {$this->db->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = %s\n                WHERE p.post_type = %s AND (pm2.meta_value IS NULL OR pm2.meta_value != '1')\n            ", '_wp_attached_file', self::WP_POSTMETA_KEY, 'attachment');
        /** @var array<string, string>[]|null $rows */
        $rows = $this->db->get_results($sql, ARRAY_A);
        if (null === $rows) {
            $this->db->query('COMMIT');

            return;
        }
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $path = 'wp-content/uploads/'.$row['path'];
            if (bunnycdn_is_path_excluded($path, $this->config->getExcluded())) {
                update_post_meta($id, self::WP_POSTMETA_EXCLUDED_KEY, true);
            }
        }
        $this->db->query('COMMIT');
    }
}
