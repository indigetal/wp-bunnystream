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

class Wizard
{
    /** @var array<string, string> */
    private array $serverVars;

    /**
     * @param array<string, string> $serverVars
     */
    public function __construct(array $serverVars)
    {
        $this->serverVars = $serverVars;
    }

    public function normalizeUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $protocol = 'http' === $this->serverVars['REQUEST_SCHEME'] ? 'http' : 'https';
        // protocol relative
        if (str_starts_with($url, '//')) {
            $url = $protocol.':'.$url;
        }
        // no protocol
        if (!str_contains($url, ':')) {
            $url = $protocol.'://'.$url;
        }

        return $url;
    }

    public function getPathPrefix(): string
    {
        $path = parse_url(site_url(), \PHP_URL_PATH);
        $pathPrefix = '';
        if (null !== $path && false !== $path && str_starts_with($path, '/') && '/' !== $path) {
            $pathPrefix = rtrim($path, '/');
        }

        return $pathPrefix;
    }
}
