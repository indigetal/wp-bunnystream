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

class Video
{
    public const STATUS_FINISHED = 4;
    private string $guid;
    private string $title;
    private int $status;
    private int $encodeProgress;
    private int $length;
    private string $thumbnailFilename;
    private ?string $collectionId;

    public function __construct(string $guid, string $title, int $status, int $encodeProgress, int $length, string $thumbnailFilename, ?string $collectionId)
    {
        $this->guid = $guid;
        $this->title = $title;
        $this->status = $status;
        $this->encodeProgress = $encodeProgress;
        $this->length = $length;
        $this->thumbnailFilename = $thumbnailFilename;
        $this->collectionId = $collectionId;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromApiResponse(array $data): self
    {
        return new self($data['guid'], $data['title'], $data['status'], $data['encodeProgress'], $data['length'], $data['thumbnailFileName'], $data['collectionId']);
    }

    public function getGuid(): string
    {
        return $this->guid;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getEncodeProgress(): int
    {
        return $this->encodeProgress;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getHumanLength(): string
    {
        $seconds = $this->length % 60;
        $minutes = floor($this->length / 60);
        if ($this->length >= 3600) {
            $hours = floor($this->length / 3600);
            $minutes = floor(($this->length - $hours * 3600) / 60);

            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    public function getThumbnailFilename(): string
    {
        return $this->thumbnailFilename;
    }

    public function getCollectionId(): ?string
    {
        return $this->collectionId;
    }
}
