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

namespace {
    function bunnycdn_activate_plugin(): void
    {
        // Migration from V1 removed - simplified plugin no longer needs legacy migration
        // Plugin activation is handled by WordPress
    }
    function bunnycdn_uninstall_plugin(): void
    {
        require_once __DIR__.'/../vendor/autoload.php';
        \Bunny\Wordpress\Config\Reset::all();
    }
    function bunnycdn_container(): \Bunny\Wordpress\Container
    {
        static $container;
        if (null !== $container) {
            return $container;
        }
        $container = new \Bunny\Wordpress\Container();

        return $container;
    }
    function bunnycdn_http_proxy(): ?string
    {
        static $proxy = '';
        if ('' !== $proxy) {
            return $proxy;
        }
        $wpProxy = new \WP_HTTP_Proxy();
        if (!$wpProxy->is_enabled()) {
            $proxy = null;

            return $proxy;
        }
        if ($wpProxy->use_authentication()) {
            $proxy = sprintf('http://%s@%s:%d', $wpProxy->authentication(), $wpProxy->host(), $wpProxy->port());
        } else {
            $proxy = sprintf('http://%s:%d', $wpProxy->host(), $wpProxy->port());
        }

        return $proxy;
    }
    function bunnycdn_offloader_remote_path(string $file): string
    {
        static $offset = null;
        static $overridePrefix = '';
        if (null === $offset) {
            if (defined('WP_CONTENT_DIR')) {
                $offset = strlen(WP_CONTENT_DIR);
                $overridePrefix = 'wp-content/';
            } else {
                $offset = strlen(ABSPATH);
            }
        }

        return $overridePrefix.ltrim(substr($file, $offset), '/');
    }
    /**
     * @param string[] $paths
     *
     * @return string[]
     */
    function bunnycdn_offloader_filter_delete_paths(array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            if (empty($path)) {
                continue;
            }
            if (str_ends_with($path, '/') || str_ends_with($path, '/wp-content') || str_ends_with($path, '/wp-content/uploads')) {
                trigger_error('bunnycdn: offloader: aborted attempt to delete '.$path, \E_USER_NOTICE);
                continue;
            }
            $result[] = $path;
        }

        return $result;
    }
    /**
     * @param string[] $excludes
     */
    function bunnycdn_is_path_excluded(string $path, array $excludes, bool $leadingSlash = false): bool
    {
        foreach ($excludes as $excludedPath) {
            if ($leadingSlash && !str_starts_with($excludedPath, '*') && !str_starts_with($excludedPath, '/')) {
                $excludedPath = '/'.$excludedPath;
            }
            if (!str_contains($excludedPath, '*')) {
                return $excludedPath === $path;
            }
            $prefix = '^';
            $suffix = '$';
            if (str_starts_with($excludedPath, '*')) {
                $prefix = '';
            }
            if (str_ends_with($excludedPath, '*')) {
                $suffix = '';
            }
            $regex = '#'.$prefix.str_replace('\\*', '(.*)', preg_quote($excludedPath)).$suffix.'#';
            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }
    /**
     * @param array<array-key, mixed> $params
     */
    function bunnycdn_stream_video_render_shortcode($params): string
    {
        $videoId = null;
        $libraryId = null;
        // video ID: [bunnycdn_stream_video id="video_id"]
        if (isset($params['id']) && is_string($params['id'])) {
            $videoId = $params['id'];
        }
        // library ID: [bunnycdn_stream_video library=123]
        if (isset($params['library']) && is_string($params['library'])) {
            $libraryId = (int) $params['library'];
        }
        if (empty($videoId) || empty($libraryId)) {
            return '[bunnycdn_stream_video error: invalid shortcode]';
        }
        $options = [];
        // other parameters: [bunnycdn_stream_video loop=true autoplay=false]
        foreach (\BUNNYCDN_STREAM_VIDEO_OPTIONS as $key => $type) {
            // shortcode params are lowered
            $paramKey = strtolower($key);
            if (isset($params[$paramKey])) {
                if ('boolean' === $type) {
                    $options[$key] = 'true' === $params[$paramKey];
                } else {
                    $options[$key] = $params[$paramKey];
                }
            } else {
                if (isset(BUNNYCDN_STREAM_VIDEO_DEFAULTS[$key])) {
                    $options[$key] = BUNNYCDN_STREAM_VIDEO_DEFAULTS[$key];
                }
            }
        }

        return bunnycdn_stream_video_embed($videoId, $libraryId, $options);
    }
    /**
     * @param array<array-key, mixed> $params
     */
    function bunnycdn_stream_video_render_block(array $params, ?string $content = null): string
    {
        $videoId = null;
        $libraryId = null;
        if (isset($params['video_id']) && is_string($params['video_id'])) {
            $videoId = $params['video_id'];
            unset($params['video_id']);
        }
        if (isset($params['library_id']) && is_string($params['library_id'])) {
            $libraryId = (int) $params['library_id'];
            unset($params['library_id']);
        }
        if (empty($videoId) || empty($libraryId)) {
            trigger_error('bunnycdn: could not render stream video block', \E_USER_WARNING);

            return '';
        }

        return bunnycdn_stream_video_embed($videoId, $libraryId, $params);
    }
    /**
     * @param array<string, bool> $options
     */
    function bunnycdn_stream_video_embed(string $videoId, int $libraryId, array $options): string
    {
        $alignOptions = ['wide', 'full'];
        $urlParams = [];
        $videoId = preg_replace('/[^a-fA-F0-9-]/', '', $videoId);
        $tokenAuth = $options['token_authentication'] ?? null;
        if (true === $tokenAuth) {
            $tokenKey = bunnycdn_stream_library_get_token($libraryId);
            if (null === $tokenKey) {
                return '<p>Error: could not render video</p>';
            }
            $expires = time() + 3600;
            $urlParams['token'] = hash('sha256', sprintf('%s%s%d', $tokenKey, $videoId, $expires));
            $urlParams['expires'] = $expires;
        }
        foreach (\BUNNYCDN_STREAM_VIDEO_OPTIONS as $key => $type) {
            if (isset($options[$key])) {
                if ('boolean' === $type) {
                    $urlParams[$key] = $options[$key] ? 'true' : 'false';
                } else {
                    $urlParams[$key] = $options[$key];
                }
            } else {
                if (isset(BUNNYCDN_STREAM_VIDEO_DEFAULTS[$key])) {
                    $urlParams[$key] = BUNNYCDN_STREAM_VIDEO_DEFAULTS[$key] ? 'true' : 'false';
                }
            }
        }
        ksort($urlParams);
        $classNames = ['wp-block-bunnycdn-block-stream-video'];
        if (isset($options['align']) && in_array($options['align'], $alignOptions, true)) {
            $classNames[] = sprintf('align%s', $options['align']);
        }
        $iframeUrl = 'https://iframe.mediadelivery.net/embed/'.$libraryId.'/'.$videoId.'?'.http_build_query($urlParams);
        $html = '<div class="'.join(' ', $classNames).'">';
        $html .= '<div style="position:relative;padding-top:56.25%;" class="bunny-stream-video">';
        $html .= '<iframe src="'.$iframeUrl.'" loading="lazy" style="border:0;position:absolute;top:0;height:100%;width:100%;" allow="accelerometer;gyroscope;autoplay;encrypted-media;picture-in-picture;" allowfullscreen="true"></iframe>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
    function bunnycdn_stream_library_get_token(int $libraryId): ?string
    {
        $cacheKey = sprintf('bunnycdn_stream_library_token_%d', $libraryId);
        $cacheValue = get_transient($cacheKey);
        if (false !== $cacheValue) {
            return (string) $cacheValue;
        }
        $container = bunnycdn_container()->getApiClient();
        try {
            $library = $container->getStreamLibrary($libraryId);
            $pullzone = $container->getPullzoneDetails($library->getPullzoneId());
            $value = $pullzone->getZoneSecurityKey();
        } catch (\Exception $e) {
            trigger_error('bunnycdn: bunnycdn_stream_library_get_token: failed to get token. '.$e->getMessage(), \E_USER_WARNING);

            return null;
        }
        set_transient($cacheKey, $value);

        return $value;
    }
    const BUNNYCDN_STREAM_VIDEO_DEFAULTS = ['autoplay' => false, 'loop' => false, 'muted' => false, 'preload' => false, 'responsive' => true];
    const BUNNYCDN_STREAM_VIDEO_OPTIONS = ['autoplay' => 'boolean', 'captions' => 'string', 'chromecast' => 'boolean', 'disableAirplay' => 'boolean', 'disableIosPlayer' => 'boolean', 'loop' => 'boolean', 'muted' => 'boolean', 'playsinline' => 'boolean', 'preload' => 'boolean', 'rememberPosition' => 'boolean', 'responsive' => 'boolean', 'showHeatmap' => 'boolean', 'showSpeed' => 'boolean', 't' => 'string'];
}
