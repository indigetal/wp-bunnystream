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

namespace Bunny\Wordpress\Api\Stream;

class Library
{
    private int $id;
    private string $name;
    private string $accessKey;
    private int $pullzoneId;
    private bool $embedTokenAuthentication;
    private string $hostname;

    public function __construct(int $id, string $name, string $accessKey, int $pullzoneId, bool $embedTokenAuthentication, string $hostname)
    {
        $this->id = $id;
        $this->name = $name;
        $this->accessKey = $accessKey;
        $this->pullzoneId = $pullzoneId;
        $this->embedTokenAuthentication = $embedTokenAuthentication;
        $this->hostname = $hostname;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromApiResponse(array $data): Library
    {
        // Use CDN hostname if available, otherwise construct from VideoLibraryId
        $hostname = $data['Hostname'] ?? $data['CDNHostname'] ?? sprintf('vz-%s.b-cdn.net', substr(md5((string)$data['Id']), 0, 8));
        return new self($data['Id'], $data['Name'], $data['ApiKey'], $data['PullZoneId'], (bool) $data['PlayerTokenAuthenticationEnabled'], $hostname);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAccessKey(): string
    {
        return $this->accessKey;
    }

    public function getPullzoneId(): int
    {
        return $this->pullzoneId;
    }

    public function isEmbedTokenAuthentication(): bool
    {
        return $this->embedTokenAuthentication;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }
}
