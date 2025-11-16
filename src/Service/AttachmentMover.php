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

namespace Bunny\Wordpress\Service;

use Bunny\Storage\Client as StorageClient;
use Bunny\Storage\FileNotFoundException;
use Bunny\Wordpress\Config\Offloader as OffloaderConfig;
use Bunny\Wordpress\Service\Exception\InvalidSQLQueryException;
use Bunny\Wordpress\Service\Exception\StorageFileAlreadyExistsException;
use Bunny\Wordpress\Utils\Offloader as OffloaderUtils;
use Bunny_WP_Plugin\GuzzleHttp\Promise;

class AttachmentMover
{
    public const LOCATION_ORIGIN = 'origin';
    public const LOCATION_STORAGE = 'storage';
    private const LOCK_TIMEOUT_SECONDS = 60 * 15;
    private \wpdb $db;
    private StorageClient $storage;
    private OffloaderConfig $config;
    private OffloaderUtils $offloaderUtils;

    public function __construct(\wpdb $db, StorageClient $storage, OffloaderConfig $config, OffloaderUtils $offloaderUtils)
    {
        $this->db = $db;
        $this->storage = $storage;
        $this->config = $config;
        $this->offloaderUtils = $offloaderUtils;
    }

    /**
     * @return array{success: bool, data: array{message: string}}
     */
    public function perform(int $batchSize): array
    {
        if (!$this->config->isEnabled()) {
            return ['success' => false, 'data' => ['message' => 'The Offloader feature is disabled.']];
        }
        $this->offloaderUtils->resetFileLocks(time() - self::LOCK_TIMEOUT_SECONDS);
        try {
            $results = $this->getAttachmentsAndLock($batchSize);
            $countResults = count($results);
        } catch (\Exception $e) {
            trigger_error('bunnycdn: '.$e->getMessage(), \E_USER_WARNING);

            return ['success' => false, 'data' => ['message' => $e->getMessage()]];
        }
        if (0 === $countResults) {
            return ['success' => true, 'data' => ['message' => 'There are no files available to be moved.']];
        }
        $errors = [];
        foreach ($results as $row) {
            $postID = (int) $row['ID'];
            try {
                $this->moveAttachmentToCDN($postID);
            } catch (\Bunny\Storage\AuthenticationException $e) {
                trigger_error('bunnycdn: '.$e->getMessage(), \E_USER_WARNING);

                return ['success' => false, 'data' => ['message' => 'Authentication to the storage failed. Make sure the Storage Zone and its password are correct.']];
            } catch (StorageFileAlreadyExistsException $e) {
                update_post_meta($postID, OffloaderUtils::WP_POSTMETA_ERROR, 'File already exists in the Storage Zone');
                trigger_error('bunnycdn: '.$e->getMessage(), \E_USER_WARNING);
                $errors[] = $e->getMessage();
            } catch (\Exception $e) {
                $attempts = (int) $row['attempts'];
                ++$attempts;
                update_post_meta($postID, OffloaderUtils::WP_POSTMETA_ATTEMPTS_KEY, $attempts);
                update_post_meta($postID, OffloaderUtils::WP_POSTMETA_ERROR, $e->getMessage());
                trigger_error('bunnycdn: '.$e->getMessage(), \E_USER_WARNING);
                $errors[] = $e->getMessage();
            }
        }
        $countErrors = count($errors);
        // all success
        if (0 === $countErrors) {
            return ['success' => true, 'data' => ['message' => sprintf('%d files moved to the BunnyCDN Storage.', $countResults)]];
        }
        // all error
        if ($countResults === $countErrors) {
            return ['success' => false, 'data' => ['message' => 'Errors:'.\PHP_EOL.\PHP_EOL.implode(\PHP_EOL, $errors)]];
        }

        // partial success
        return ['success' => true, 'data' => ['message' => sprintf("%d files moved to the BunnyCDN Storage, however %d failed to be moved.\n\nErrors:\n%s", $countResults, $countErrors, implode(\PHP_EOL, $errors))]];
    }

    /**
     * @return array<string, string>[]
     */
    private function getAttachmentsAndLock(int $limit): array
    {
        $this->db->query('START TRANSACTION');
        $sql = $this->db->prepare("\n                    SELECT p.ID, pm2.meta_value AS attempts, pm6.meta_value AS filename\n                    FROM {$this->db->posts} p\n                    LEFT JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s\n                    LEFT JOIN {$this->db->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = %s\n                    LEFT JOIN {$this->db->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = %s\n                    LEFT JOIN {$this->db->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = %s\n                    LEFT JOIN {$this->db->postmeta} pm5 ON p.ID = pm5.post_id AND pm5.meta_key = %s\n                    LEFT JOIN {$this->db->postmeta} pm6 ON p.ID = pm6.post_id AND pm6.meta_key = %s\n                    WHERE p.post_type = %s AND pm.meta_key IS NULL AND pm3.meta_key IS NULL AND pm4.meta_key IS NULL AND pm5.meta_key IS NULL\n                    ORDER BY pm2.meta_value ASC, p.ID DESC\n                    LIMIT %d\n                    FOR UPDATE\n            ", OffloaderUtils::WP_POSTMETA_KEY, OffloaderUtils::WP_POSTMETA_ATTEMPTS_KEY, '_wp_attachment_context', OffloaderUtils::WP_POSTMETA_UPLOAD_LOCK_KEY, OffloaderUtils::WP_POSTMETA_ERROR, '_wp_attached_file', 'attachment', $limit);
        if (null === $sql) {
            throw new InvalidSQLQueryException();
        }
        /** @var array<string, string>[]|null $rows */
        $rows = $this->db->get_results($sql, ARRAY_A);
        if (null === $rows) {
            throw new \Exception('There was an error obtaining the list of files to be moved.');
        }
        $result = [];
        // lock attachments
        foreach ($rows as $row) {
            $attachmentId = $row['ID'];
            $filename = 'wp-content/uploads/'.$row['filename'];
            if (bunnycdn_is_path_excluded($filename, $this->config->getExcluded())) {
                continue;
            }
            $result[] = ['ID' => $attachmentId, 'attempts' => $row['attempts']];
            update_post_meta((int) $attachmentId, OffloaderUtils::WP_POSTMETA_UPLOAD_LOCK_KEY, time());
        }
        $this->db->query('COMMIT');

        return $result;
    }

    private function moveAttachmentToCDN(int $attachmentId, bool $override = false): void
    {
        $imageMetadata = wp_get_attachment_metadata($attachmentId);
        if (false === $imageMetadata) {
            throw new \Exception('File not found.');
        }
        if (isset($imageMetadata['file']) && (str_starts_with($imageMetadata['file'], 'http://') || str_starts_with($imageMetadata['file'], 'https://'))) {
            throw new \Exception('Remote files are not supported.');
        }
        $attachedFileMetadata = get_post_meta($attachmentId, '_wp_attached_file', true);
        if (str_starts_with($attachedFileMetadata, 'http://') || str_starts_with($attachedFileMetadata, 'https://')) {
            throw new \Exception('Remote files are not supported.');
        }
        $path = 'wp-content/uploads/'.$attachedFileMetadata;
        if (bunnycdn_is_path_excluded($path, $this->config->getExcluded())) {
            update_post_meta($attachmentId, OffloaderUtils::WP_POSTMETA_EXCLUDED_KEY, true);

            return;
        }
        $file = get_attached_file($attachmentId);
        if (false === $file || '' === $file) {
            throw new \Exception('File not found.');
        }
        $storage = $this->storage;
        $fileRemote = bunnycdn_offloader_remote_path($file);
        $filesToUpload = [];
        if (file_exists($file)) {
            $filesToUpload[$file] = $fileRemote;
        }
        if (isset($imageMetadata['original_image'])) {
            $localPath = path_join(dirname($file), $imageMetadata['original_image']);
            if (file_exists($localPath)) {
                $filesToUpload[$localPath] = path_join(dirname($fileRemote), $imageMetadata['original_image']);
            }
        }
        if (!empty($imageMetadata['sizes']) && is_array($imageMetadata['sizes'])) {
            foreach ($imageMetadata['sizes'] as $sizeInfo) {
                $localPath = path_join(dirname($file), $sizeInfo['file']);
                if (!file_exists($localPath)) {
                    continue;
                }
                $remotePath = bunnycdn_offloader_remote_path($localPath);
                $filesToUpload[$localPath] = $remotePath;
            }
        }
        // if files already exist, we won't override it
        if (!$override) {
            foreach ($filesToUpload as $localPath => $remotePath) {
                try {
                    $info = $storage->info($remotePath);
                    if ($info->getSize() === filesize($localPath) && $info->getChecksum() === hash_file('sha256', $localPath)) {
                        continue;
                    }
                    throw new StorageFileAlreadyExistsException($remotePath);
                } catch (FileNotFoundException $e) {
                    continue;
                }
            }
        }
        // copy files to storage
        $promises = [];
        foreach ($filesToUpload as $localPath => $remotePath) {
            $promises[$remotePath] = $storage->uploadAsync($localPath, $remotePath);
        }
        Promise\Utils::unwrap($promises);
        if (0 === count($filesToUpload)) {
            trigger_error(sprintf('bunnycdn: attachment %d (%s) had no local files during moveAttachmentToCDN', $attachmentId, $attachedFileMetadata), \E_USER_NOTICE);
        }
        // make sure at least the original file is available in the remote
        try {
            $fileInfo = $storage->info($fileRemote);
            if (file_exists($file) && ($fileInfo->getSize() !== filesize($file) || $fileInfo->getChecksum() !== hash_file('sha256', $file))) {
                throw new \Exception('Contents mismatched.');
            }
        } catch (\Exception $e) {
            throw new \Exception(sprintf('File %s could not be found in storage after upload.', $fileRemote));
        }
        // start db transaction
        $this->db->query('START TRANSACTION');
        try {
            // update metadata
            update_post_meta($attachmentId, OffloaderUtils::WP_POSTMETA_KEY, 1);
            delete_post_meta($attachmentId, OffloaderUtils::WP_POSTMETA_ATTEMPTS_KEY);
            delete_post_meta($attachmentId, OffloaderUtils::WP_POSTMETA_ERROR);
            delete_post_meta($attachmentId, OffloaderUtils::WP_POSTMETA_UPLOAD_LOCK_KEY);
            // commit db changes
            $this->db->query('COMMIT');
        } catch (\Exception $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        // delete original files
        foreach ($filesToUpload as $localPath => $remotePath) {
            if (file_exists($localPath)) {
                unlink($localPath);
            }
        }
    }

    public function performById(int $id): ?string
    {
        try {
            $this->moveAttachmentToCDN($id, true);
        } catch (\Bunny\Storage\AuthenticationException $e) {
            trigger_error('bunnycdn: '.$e->getMessage(), \E_USER_WARNING);

            return 'Authentication to the storage failed. Make sure the Storage Zone and its password are correct.';
        } catch (\Exception $e) {
            trigger_error('bunnycdn: '.$e->getMessage(), \E_USER_WARNING);

            return $e->getMessage();
        }

        return null;
    }

    public function resolveConflict(int $attachment_id, string $keep): void
    {
        $type = get_post_type($attachment_id);
        if ('attachment' !== $type) {
            throw new \Exception('Invalid attachment ID');
        }
        $metadata = wp_get_attachment_metadata($attachment_id);
        $file = get_attached_file($attachment_id);
        if (empty($file) || empty($metadata)) {
            throw new \Exception('Invalid attachment metadata');
        }
        if (self::LOCATION_ORIGIN === $keep) {
            $this->resolveConflictKeepOrigin($attachment_id, $file, $metadata);

            return;
        }
        if (self::LOCATION_STORAGE === $keep) {
            $this->resolveConflictKeepStorage($attachment_id, $file, $metadata);

            return;
        }
        throw new \Exception('Invalid keep location.');
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private function resolveConflictKeepOrigin(int $attachment_id, string $file, array $metadata): void
    {
        if (!file_exists($file)) {
            throw new \Exception('The origin file "'.$file.'" is not available.');
        }
        $remotePath = bunnycdn_offloader_remote_path($file);
        $to_delete = [$remotePath];
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (empty($size['file'])) {
                    continue;
                }
                $to_delete[] = path_join(dirname($remotePath), $size['file']);
            }
        }
        $to_delete = bunnycdn_offloader_filter_delete_paths($to_delete);
        try {
            foreach ($to_delete as $file) {
                $this->storage->delete($file);
            }
        } catch (FileNotFoundException $e) {
            // noop
        }
        $error = $this->performById($attachment_id);
        if (null !== $error) {
            throw new \Exception($error);
        }
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private function resolveConflictKeepStorage(int $attachment_id, string $file, array $metadata): void
    {
        $path = bunnycdn_offloader_remote_path($file);
        $files = [$path];
        if (is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                $files[] = path_join(dirname($path), $size['file']);
            }
        }
        // check if the files are indeed in the storage zone
        $promises = [];
        foreach ($files as $remote_path) {
            $promise = new Promise\Promise(function () use (&$promise, $remote_path) {
                if (!$this->storage->exists($remote_path)) {
                    throw new FileNotFoundException($remote_path);
                }
                /** @var Promise\Promise $promise */
                $promise->resolve(true);
            });
            $promises[$remote_path] = $promise;
            unset($promise);
        }
        Promise\Utils::unwrap($promises);
        $this->db->query('START TRANSACTION');
        try {
            update_post_meta($attachment_id, OffloaderUtils::WP_POSTMETA_KEY, 1);
            delete_post_meta($attachment_id, OffloaderUtils::WP_POSTMETA_ATTEMPTS_KEY);
            delete_post_meta($attachment_id, OffloaderUtils::WP_POSTMETA_ERROR);
            delete_post_meta($attachment_id, OffloaderUtils::WP_POSTMETA_UPLOAD_LOCK_KEY);
            $this->db->query('COMMIT');
        } catch (\Exception $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        foreach ($files as $file) {
            $local_path = ABSPATH.$file;
            if (file_exists($local_path)) {
                @unlink($local_path);
            }
        }
    }
}
