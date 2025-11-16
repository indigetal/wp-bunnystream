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

namespace Bunny\Wordpress\Config;

class Stream
{
    public const STORAGE_REPLICATION_REGIONS = ['uk' => 'Europe (London)', 'se' => 'Europe (Stockholm)', 'ny' => 'US East (New York)', 'la' => 'US West (Los Angeles)', 'sg' => 'Asia (Singapore)', 'syd' => 'Oceania (Sydney)', 'br' => 'LATAM (Sao Paulo)', 'jh' => 'Africa (Johannesburg)'];
    private bool $librariesAll;
    /** @var int[] */
    private array $libraries;
    private bool $allowUploads;

    /**
     * @param int[] $libraries
     */
    public function __construct(bool $librariesAll, array $libraries, bool $allowUploads)
    {
        $this->librariesAll = $librariesAll;
        $this->libraries = $libraries;
        $this->allowUploads = $allowUploads;
    }

    public function isLibrariesAll(): bool
    {
        return $this->librariesAll;
    }

    /**
     * @return int[]
     */
    public function getLibraries(): array
    {
        return $this->libraries;
    }

    public function isAllowUploads(): bool
    {
        return $this->allowUploads;
    }

    public static function fromWpOptions(): self
    {
        $librariesAll = '0' !== get_option('bunnycdn_stream_libraries_all', '1');
        $libraries = get_option('bunnycdn_stream_libraries', []);
        $allowUploads = '1' === get_option('bunnycdn_stream_allow_uploads', '0');

        return new self($librariesAll, $libraries, $allowUploads);
    }

    /**
     * @param array<string, mixed> $postData
     */
    public function handlePost(array $postData): void
    {
        if ('1' !== ($postData['api_unavailable'] ?? '0')) {
            $libraries = $postData['libraries'] ?? [];
            $libraries = array_map(fn ($item): int => (int) $item, $libraries);
            $libraries = array_unique($libraries);
            $this->libraries = $libraries;
        }
        $this->allowUploads = '1' === ($postData['allow_uploads'] ?? '0');
        $this->librariesAll = '1' === ($postData['libraries_all'] ?? '0');
    }

    public function saveToWpOptions(): void
    {
        update_option('bunnycdn_stream_libraries_all', $this->librariesAll ? '1' : '0');
        update_option('bunnycdn_stream_libraries', $this->libraries);
        update_option('bunnycdn_stream_allow_uploads', $this->allowUploads ? '1' : '0');
    }
}
