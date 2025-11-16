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

namespace Bunny\Wordpress\Api\Pullzone;

use Bunny\Wordpress\Config\Optimizer;
use Bunny\Wordpress\Utils\Number;

class Details
{
    private int $id;
    private string $name;
    /** @var string[] */
    private array $hostnames;
    private bool $enableCors;
    /** @var string[] */
    private array $corsExtensions;
    private Optimizer $config;
    private int $bandwidthUsed;
    private float $charges;
    /** @var Edgerule[] */
    private array $edgerules;
    private bool $zoneSecurityEnabled;
    private string $zoneSecurityKey;

    /**
     * @param string[] $hostnames
     * @param string[] $corsExtensions
     * @param Edgerule[] $edgerules
     */
    public function __construct(int $id, string $name, array $hostnames, bool $enableCors, array $corsExtensions, Optimizer $config, int $bandwidthUsed, float $charges, array $edgerules, bool $zoneSecurityEnabled, string $zoneSecurityKey)
    {
        $this->id = $id;
        $this->name = $name;
        $this->hostnames = $hostnames;
        $this->enableCors = $enableCors;
        $this->corsExtensions = $corsExtensions;
        $this->config = $config;
        $this->bandwidthUsed = $bandwidthUsed;
        $this->charges = $charges;
        $this->edgerules = $edgerules;
        $this->zoneSecurityEnabled = $zoneSecurityEnabled;
        $this->zoneSecurityKey = $zoneSecurityKey;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string[]
     */
    public function getHostnames(): array
    {
        return $this->hostnames;
    }

    public function isEnableCors(): bool
    {
        return $this->enableCors;
    }

    /**
     * @return string[]
     */
    public function getCorsExtensions(): array
    {
        return $this->corsExtensions;
    }

    public function getConfig(): Optimizer
    {
        return $this->config;
    }

    public function getBandwidthUsedHumanReadable(): string
    {
        return Number::bytesToString($this->bandwidthUsed, 2);
    }

    public function getBandwidthAverageCostHumanReadable(): string
    {
        if (0 === $this->bandwidthUsed) {
            return Number::floatToMoney(0, 4);
        }
        $amount = $this->charges / $this->bandwidthUsed * 1024 * 1024 * 1024;

        return Number::floatToMoney($amount, 4);
    }

    public function getChargesHumanReadable(): string
    {
        return Number::floatToMoney($this->charges);
    }

    /**
     * @return Edgerule[]
     */
    public function getEdgerules(): array
    {
        return $this->edgerules;
    }

    public function isZoneSecurityEnabled(): bool
    {
        return $this->zoneSecurityEnabled;
    }

    public function getZoneSecurityKey(): string
    {
        return $this->zoneSecurityKey;
    }
}
