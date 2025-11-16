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

    public function __construct(int $id, string $name, string $accessKey, int $pullzoneId, bool $embedTokenAuthentication)
    {
        $this->id = $id;
        $this->name = $name;
        $this->accessKey = $accessKey;
        $this->pullzoneId = $pullzoneId;
        $this->embedTokenAuthentication = $embedTokenAuthentication;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromApiResponse(array $data): Library
    {
        return new self($data['Id'], $data['Name'], $data['ApiKey'], $data['PullZoneId'], (bool) $data['PlayerTokenAuthenticationEnabled']);
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
}
