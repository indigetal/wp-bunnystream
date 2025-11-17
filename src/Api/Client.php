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

namespace Bunny\Wordpress\Api;

use Bunny\Wordpress\Api\Exception\AuthorizationException;
use Bunny\Wordpress\Api\Exception\InvalidJsonException;
use Bunny\Wordpress\Api\Exception\NotFoundException;
use Bunny_WP_Plugin\GuzzleHttp\Client as HttpClient;

class Client
{
    public const BASE_URL = 'https://api.bunny.net';
    private HttpClient $httpClient;

    public function __construct(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    private function request(string $method, string $uri, ?string $body = null): array
    {
        $options = ['headers' => []];
        if ('POST' === $method) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $body;
        }
        $response = $this->httpClient->request($method, $uri, $options);
        if (401 === $response->getStatusCode()) {
            throw new AuthorizationException();
        }
        if (404 === $response->getStatusCode()) {
            throw new NotFoundException();
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
            throw new \Exception('api.bunny.net: no response ('.$response->getStatusCode().')');
        }
        $data = json_decode($response->getBody()->getContents(), true);
        if (null === $data) {
            return [];
        }
        if (!is_array($data)) {
            throw new \Exception('api.bunny.net: invalid JSON response');
        }

        return $data;
    }

    public function getUser(): User
    {
        $data = $this->request('GET', 'user');
        if (empty($data)) {
            throw new \Exception('Failure loading user from the api');
        }
        $name = '';
        if (!empty($data['FirstName']) || !empty($data['LastName'])) {
            $name = sprintf('%s %s', $data['FirstName'], $data['LastName']);
        }

        return new User($name, $data['Email']);
    }

    public function getStorageZone(int $id): Storagezone\Details
    {
        $data = $this->request('GET', sprintf('storagezone/%d', $id));

        return new Storagezone\Details($data['Id'], $data['Name'], $data['Password']);
    }

    /**
     * @param string[] $replicationRegions
     */
    public function createStorageZone(string $name, string $region, array $replicationRegions = []): Storagezone\Details
    {
        $replicationRegions = array_map(fn ($item) => strtoupper($item), $replicationRegions);
        $body = json_encode(['Name' => $name, 'Region' => $region, 'ReplicationRegions' => $replicationRegions, 'ZoneTier' => '1']);
        if (false === $body) {
            throw new InvalidJsonException();
        }
        $data = $this->request('POST', 'storagezone', $body);

        return new Storagezone\Details($data['Id'], $data['Name'], $data['Password']);
    }

    public function updateStorageZoneCron(int $id, string $pathPrefix, string $syncToken): void
    {
        if (0 === $id) {
            throw new \Exception('Invalid storage zone ID');
        }
        $body = json_encode(['WordPressCronToken' => $syncToken, 'WordPressCronPath' => $pathPrefix]);
        if (false === $body) {
            throw new InvalidJsonException();
        }
        $this->request('POST', sprintf('storagezone/%d', $id), $body);
    }

    /**
     * @return Stream\Library[]
     */
    public function getStreamLibraries(): array
    {
        $rows = $this->request('GET', 'videolibrary');

        return array_map(fn ($item) => Stream\Library::fromApiResponse($item), $rows);
    }

    public function getStreamLibrary(int $id): Stream\Library
    {
        $item = $this->request('GET', sprintf('videolibrary/%d', $id));

        return Stream\Library::fromApiResponse($item);
    }

    /**
     * @param string[] $replicationRegions
     */
    public function createStreamLibrary(string $name, array $replicationRegions): Stream\Library
    {
        $replicationRegions = array_map(fn ($item) => strtoupper($item), $replicationRegions);
        $body = json_encode(['Name' => $name, 'ReplicationRegions' => $replicationRegions]);
        if (false === $body) {
            throw new InvalidJsonException();
        }
        $data = $this->request('POST', 'videolibrary', $body);

        return Stream\Library::fromApiResponse($data);
    }

    /**
     * @return Stream\Collection[]
     */
    public function getStreamCollections(Stream\Library $library): array
    {
        // @TODO pagination
        $response = $this->httpClient->request('GET', sprintf('https://video.bunnycdn.com/library/%d/collections?itemsPerPage=1000', $library->getId()), ['headers' => ['AccessKey' => $library->getAccessKey(), 'Content-Type' => 'application/json']]);
        if (200 !== $response->getStatusCode()) {
            throw new \Exception($response->getBody()->getContents());
        }
        $data = json_decode($response->getBody()->getContents(), true);
        if (null === $data) {
            return [];
        }
        if (!is_array($data)) {
            throw new \Exception('api.bunny.net: invalid JSON response');
        }

        return array_map(fn ($item) => Stream\Collection::fromApiResponse($item), $data['items']);
    }

    public function createStreamVideo(Stream\Library $library, string $title): Stream\Video
    {
        $response = $this->httpClient->request('POST', sprintf('https://video.bunnycdn.com/library/%d/videos', $library->getId()), ['headers' => ['AccessKey' => $library->getAccessKey(), 'Content-Type' => 'application/json'], 'body' => json_encode(['title' => $title], \JSON_THROW_ON_ERROR)]);
        if (200 !== $response->getStatusCode()) {
            throw new \Exception($response->getBody()->getContents());
        }
        $data = json_decode($response->getBody()->getContents(), true);
        if (null === $data) {
            throw new \Exception('api.bunny.net: empty response');
        }
        if (!is_array($data)) {
            throw new \Exception('api.bunny.net: invalid JSON response');
        }

        return Stream\Video::fromApiResponse($data);
    }

    public function getStreamVideo(Stream\Library $library, string $uuid): Stream\Video
    {
        $response = $this->httpClient->request('GET', sprintf('https://video.bunnycdn.com/library/%d/videos/%s', $library->getId(), $uuid), ['headers' => ['AccessKey' => $library->getAccessKey()]]);
        if (200 !== $response->getStatusCode()) {
            throw new \Exception($response->getBody()->getContents());
        }
        $data = json_decode($response->getBody()->getContents(), true);
        if (null === $data) {
            throw new \Exception('api.bunny.net: empty response');
        }
        if (!is_array($data)) {
            throw new \Exception('api.bunny.net: invalid JSON response');
        }

        return Stream\Video::fromApiResponse($data);
    }

    /**
     * @return Stream\Video[]
     */
    public function getStreamVideos(Stream\Library $library, ?string $collectionId): array
    {
        $url = sprintf('https://video.bunnycdn.com/library/%d/videos', $library->getId());
        if (null !== $collectionId) {
            $url .= sprintf('?collection=%s', $collectionId);
        }
        $response = $this->httpClient->request('GET', $url, ['headers' => ['AccessKey' => $library->getAccessKey()]]);
        if (200 !== $response->getStatusCode()) {
            throw new \Exception($response->getBody()->getContents());
        }
        $data = json_decode($response->getBody()->getContents(), true);
        if (null === $data) {
            throw new \Exception('api.bunny.net: empty response');
        }
        if (!is_array($data)) {
            throw new \Exception('api.bunny.net: invalid JSON response');
        }

        return array_map(fn ($item) => Stream\Video::fromApiResponse($item), $data['items']);
    }
}
