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

namespace Bunny\Wordpress\REST;

use Bunny\Wordpress\Api\Client as ApiClient;
use Bunny\Wordpress\Api\Exception\NotFoundException;
use Bunny\Wordpress\Api\Pullzone;
use Bunny\Wordpress\Api\Stream\Video;
use Bunny\Wordpress\Config\Offloader as OffloaderConfig;
use Bunny\Wordpress\Config\Stream as StreamConfig;
use Bunny\Wordpress\Service\AttachmentCounter;
use Bunny\Wordpress\Service\AttachmentMover;

class Controller
{
    private AttachmentCounter $attachmentCounter;
    private AttachmentMover $attachmentMover;
    private OffloaderConfig $offloaderConfig;
    private StreamConfig $streamConfig;
    private ApiClient $apiClient;
    private bool $isAgencyMode;

    public function __construct(AttachmentCounter $attachmentCounter, AttachmentMover $attachmentMover, OffloaderConfig $config, StreamConfig $streamConfig, ApiClient $apiClient, bool $isAgencyMode)
    {
        $this->attachmentCounter = $attachmentCounter;
        $this->attachmentMover = $attachmentMover;
        $this->offloaderConfig = $config;
        $this->streamConfig = $streamConfig;
        $this->apiClient = $apiClient;
        $this->isAgencyMode = $isAgencyMode;
    }

    public function register(): void
    {
        register_rest_route('bunnycdn/v2', '/offloader/sync', ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'offloaderSync'], 'show_in_index' => false, 'permission_callback' => '__return_true']);
        register_rest_route('bunnycdn/v2', '/stream/config', ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'streamConfig'], 'show_in_index' => false, 'permission_callback' => [$this, 'streamLibraryCheck']]);
        register_rest_route('bunnycdn/v2', '/stream/videos', ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'streamVideoList'], 'show_in_index' => false, 'permission_callback' => [$this, 'streamLibraryCheck']]);
        register_rest_route('bunnycdn/v2', '/stream/video', ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$this, 'streamVideoPost'], 'show_in_index' => false, 'permission_callback' => [$this, 'streamLibraryCanUpload']]);
        register_rest_route('bunnycdn/v2', '/stream/videoStatus', ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'streamVideoStatus'], 'show_in_index' => false, 'permission_callback' => [$this, 'streamLibraryCanUpload']]);
    }

    public function offloaderSync(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!$this->offloaderConfig->isConfigured() || !$this->offloaderConfig->isEnabled() || !$this->offloaderConfig->isSyncExisting()) {
            trigger_error('bunnycdn: offloader: This feature is not available', \E_USER_WARNING);

            return new \WP_REST_Response(['success' => false, 'message' => 'This feature is not available'], 404);
        }
        // authentication
        $token = $request->get_header('X-Bunny-WP-Token');
        $tokenHash = $this->offloaderConfig->getSyncTokenHash();
        if (null === $token || null === $tokenHash || !password_verify($token, $tokenHash)) {
            trigger_error('bunnycdn: offloader: Invalid authentication token', \E_USER_WARNING);

            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid authentication token'], 401);
        }
        // check if there are files left to sync
        $count = $this->attachmentCounter->count();
        if (0 === $count[AttachmentCounter::LOCAL]) {
            return new \WP_REST_Response(['success' => false, 'message' => 'There are no attachments to sync', 'remaining_files' => 0], 200);
        }
        // move attachments
        $batchSize = $request->get_param('batch_size') ?? 5;
        $result = $this->attachmentMover->perform($batchSize);
        update_option('_bunnycdn_offloader_last_sync', time());
        // response
        $count = $this->attachmentCounter->count();
        if (false === $result['success']) {
            trigger_error('bunnycdn: offloader: '.$result['data']['message'], \E_USER_WARNING);
        }

        return new \WP_REST_Response(['success' => $result['success'], 'message' => $result['data']['message'], 'remaining_files' => $count[AttachmentCounter::LOCAL]], 200);
    }

    public function streamConfig(): \WP_REST_Response
    {
        $allowUploads = current_user_can('upload_files') && ($this->streamConfig->isAllowUploads() || current_user_can('manage_options'));
        $libraries = [];
        try {
            $allowedLibraries = $this->streamConfig->getLibraries();
            $allowedLibrariesAll = $this->streamConfig->isLibrariesAll();
            foreach ($this->apiClient->getStreamLibraries() as $videoLibrary) {
                if (!$allowedLibrariesAll && !in_array($videoLibrary->getId(), $allowedLibraries, true)) {
                    continue;
                }
                $collections = $this->apiClient->getStreamCollections($videoLibrary);
                $libraries[] = ['value' => $videoLibrary->getId(), 'label' => $videoLibrary->getName(), 'collections' => array_map(fn ($item) => ['value' => $item->getId(), 'label' => $item->getName()], $collections), 'token_authentication' => $videoLibrary->isEmbedTokenAuthentication()];
            }
        } catch (\Bunny_WP_Plugin\GuzzleHttp\Exception\ConnectException $e) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Could not connect to the bunny.net API.'], 500);
        } catch (\Bunny\Wordpress\Api\Exception\AuthorizationException $e) {
            return new \WP_REST_Response(['success' => false, 'message' => 'The bunny.net plugin is not configured, but you still can embed videos manually.'], 401);
        } catch (\Exception $e) {
            trigger_error('bunnycdn: streamConfig: '.$e->getMessage(), \E_USER_WARNING);

            return new \WP_REST_Response(['success' => false, 'message' => 'Unexpected error occurred.'], 500);
        }

        return new \WP_REST_Response(['success' => true, 'config' => ['allow_uploads' => $allowUploads, 'manage_url' => add_query_arg(['page' => 'bunnycdn', 'section' => 'stream'], admin_url('admin.php'))], 'libraries' => $libraries], 200, ['Cache-Control' => 'private']);
    }

    public function streamVideoList(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!current_user_can('edit_posts')) {
            return new \WP_REST_Response(['success' => false, 'message' => 'User does not have permission to execute this action.'], 401);
        }
        $libraryId = (int) $request->get_param('library_id');
        if (0 === $libraryId) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid "library_id" parameter.'], 400);
        }
        $collectionId = (string) $request->get_param('collection_id');
        if ('' === $collectionId) {
            $collectionId = null;
        }
        try {
            $allowedLibraries = $this->streamConfig->getLibraries();
            $allowedLibrariesAll = $this->streamConfig->isLibrariesAll();
            if (!$allowedLibrariesAll && !in_array($libraryId, $allowedLibraries, true)) {
                throw new NotFoundException();
            }
            $library = $this->apiClient->getStreamLibrary($libraryId);
            $pullzone = $this->apiClient->getPullzoneDetails($library->getPullzoneId());
        } catch (NotFoundException $e) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Video Library not found.'], 404);
        } catch (\Bunny_WP_Plugin\GuzzleHttp\Exception\ConnectException $e) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Could not connect to the bunny.net API.'], 500);
        } catch (\Exception $e) {
            return new \WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
        $result = [];
        foreach ($this->apiClient->getStreamVideos($library, $collectionId) as $video) {
            if (Video::STATUS_FINISHED !== $video->getStatus()) {
                continue;
            }
            $result[] = ['uuid' => $video->getGuid(), 'title' => $video->getTitle(), 'duration' => $video->getHumanLength(), 'thumbnail' => $this->getVideoThumbnailUrl($pullzone, $video), 'preview' => $this->getVideoPreviewUrl($pullzone, $video), 'collection_id' => $video->getCollectionId()];
        }

        return new \WP_REST_Response(['success' => true, 'items' => $result]);
    }

    public function streamVideoPost(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        if (empty($params['library_id'])) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Missing "library_id" parameter.'], 400);
        }
        $libraryId = (int) $params['library_id'];
        if (0 === $libraryId) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid "library_id" parameter.'], 400);
        }
        if (empty($params['filename'])) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Missing "filename" parameter.'], 400);
        }
        $filename = $params['filename'];
        try {
            $allowedLibraries = $this->streamConfig->getLibraries();
            $allowedLibrariesAll = $this->streamConfig->isLibrariesAll();
            if (!$allowedLibrariesAll && !in_array($libraryId, $allowedLibraries, true)) {
                throw new NotFoundException();
            }
            $library = $this->apiClient->getStreamLibrary($libraryId);
            $video = $this->apiClient->createStreamVideo($library, $filename);
            $authExpires = time() + 60 * 60 * 24;
            // 24 hours
            $authSign = hash('sha256', sprintf('%d%s%d%s', $library->getId(), $library->getAccessKey(), $authExpires, $video->getGuid()));

            return new \WP_REST_Response(['success' => true, 'data' => ['library_id' => $libraryId, 'video_id' => $video->getGuid(), 'auth_expires' => $authExpires, 'auth_signature' => $authSign]]);
        } catch (NotFoundException $e) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Video Library not found.'], 404);
        } catch (\Bunny_WP_Plugin\GuzzleHttp\Exception\ConnectException $e) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Could not connect to the bunny.net API.'], 500);
        } catch (\Exception $e) {
            return new \WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function streamVideoStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        $libraryId = (int) $request->get_param('library_id');
        $videoId = $request->get_param('video_id');
        if (0 === $libraryId) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid "library_id" parameter.'], 400);
        }
        if (36 !== strlen($videoId)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid "video_id" parameter.'], 400);
        }
        try {
            $library = $this->apiClient->getStreamLibrary($libraryId);
            $video = $this->apiClient->getStreamVideo($library, $videoId);

            return new \WP_REST_Response(['success' => true, 'status' => $video->getStatus(), 'encode_progress' => $video->getEncodeProgress()]);
        } catch (\Bunny_WP_Plugin\GuzzleHttp\Exception\ConnectException $e) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Could not connect to the bunny.net API.'], 500);
        } catch (\Exception $e) {
            return new \WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function streamLibraryCheck(\WP_REST_Request $request): bool
    {
        return !$this->isAgencyMode && current_user_can('edit_posts');
    }

    public function streamLibraryCanUpload(\WP_REST_Request $request): bool
    {
        return !$this->isAgencyMode && current_user_can('upload_files');
    }

    private function getVideoPreviewUrl(Pullzone\Details $pullzone, Video $video): string
    {
        $url = sprintf('https://%s/%s/%s', $pullzone->getHostnames()[0], $video->getGuid(), 'preview.webp');
        if (!$pullzone->isZoneSecurityEnabled()) {
            return $url;
        }

        return $this->signUrl($url, $pullzone->getZoneSecurityKey(), 300);
    }

    private function getVideoThumbnailUrl(Pullzone\Details $pullzone, Video $video): string
    {
        $url = sprintf('https://%s/%s/%s', $pullzone->getHostnames()[0], $video->getGuid(), $video->getThumbnailFilename());
        if (!$pullzone->isZoneSecurityEnabled()) {
            return $url;
        }

        return $this->signUrl($url, $pullzone->getZoneSecurityKey(), 3600);
    }

    private function signUrl(string $url, string $securityKey, int $expirationInSeconds): string
    {
        $url_path = (string) parse_url($url, \PHP_URL_PATH);
        $expires = time() + $expirationInSeconds;
        $hashableBase = $securityKey.$url_path.$expires;
        $token = hash('sha256', $hashableBase, true);
        $token = base64_encode($token);
        $token = strtr($token, '+/', '-_');
        $token = str_replace('=', '', $token);

        return sprintf('%s?token=%s&expires=%d', $url, $token, $expires);
    }
}
