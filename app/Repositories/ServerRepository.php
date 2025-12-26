<?php

declare(strict_types=1);

namespace DeployerPHP\Repositories;

use DeployerPHP\DTOs\ServerDTO;
use DeployerPHP\Services\InventoryService;

/**
 * Repository for server CRUD operations using inventory storage.
 *
 * Stores servers as an array of objects to handle any special characters in server names.
 */
final class ServerRepository
{
    private const PREFIX = 'servers';

    private ?InventoryService $inventory = null;

    /** @var array<int, array<string, mixed>> */
    private array $servers = [];

    //
    // Public
    // ----

    /**
     * Set the inventory service instance to use for storage operations.
     */
    public function loadInventory(InventoryService $inventory): void
    {
        $this->inventory = $inventory;

        $servers = $inventory->get(self::PREFIX);
        if (!is_array($servers)) {
            $servers = [];
            $inventory->set(self::PREFIX, $servers);
        }

        /** @var array<int, array<string, mixed>> $servers */
        $this->servers = $servers;
    }

    /**
     * Create a new server in the inventory.
     */
    public function create(ServerDTO $server): void
    {
        $this->assertInventoryLoaded();

        $existingName = $this->findByName($server->name);
        if (null !== $existingName) {
            throw new \RuntimeException("Server '{$server->name}' already exists");
        }

        $existingHost = $this->findByHost($server->host);
        if (null !== $existingHost) {
            throw new \RuntimeException("Host '{$server->host}' is already used by server '{$existingHost->name}'");
        }

        $this->servers[] = $this->dehydrateServerDTO($server);

        $this->inventory->set(self::PREFIX, $this->servers);
    }

    /**
     * Find a server by name.
     */
    public function findByName(string $name): ?ServerDTO
    {
        $this->assertInventoryLoaded();

        foreach ($this->servers as $server) {
            if (isset($server['name']) && $server['name'] === $name) {
                return $this->hydrateServerDTO($server);
            }
        }

        return null;
    }

    /**
     * Find a server by host.
     */
    public function findByHost(string $host): ?ServerDTO
    {
        $this->assertInventoryLoaded();

        foreach ($this->servers as $server) {
            if (isset($server['host']) && $server['host'] === $host) {
                return $this->hydrateServerDTO($server);
            }
        }

        return null;
    }

    /**
     * Get all servers from inventory.
     *
     * @return array<int, ServerDTO>
     */
    public function all(): array
    {
        $this->assertInventoryLoaded();

        $result = [];
        foreach ($this->servers as $server) {
            $result[] = $this->hydrateServerDTO($server);
        }

        return $result;
    }

    /**
     * Delete a server from inventory.
     */
    public function delete(string $name): void
    {
        $this->assertInventoryLoaded();

        $filtered = [];
        foreach ($this->servers as $server) {
            if (isset($server['name']) && $server['name'] !== $name) {
                $filtered[] = $server;
            }
        }

        $this->servers = $filtered;

        $this->inventory->set(self::PREFIX, $this->servers);
    }

    //
    // Private
    // ----

    /**
     * Ensure inventory service is loaded before operations.
     *
     * @throws \RuntimeException If inventory is not set
     * @phpstan-assert !null $this->inventory
     */
    private function assertInventoryLoaded(): void
    {
        if ($this->inventory === null) {
            throw new \RuntimeException('Inventory not set. Call loadInventory() first.');
        }
    }

    /**
     * Convert ServerDTO to array for storage.
     *
     * @return array<string, mixed>
     */
    private function dehydrateServerDTO(ServerDTO $server): array
    {
        return [
            'name' => $server->name,
            'host' => $server->host,
            'port' => $server->port,
            'username' => $server->username,
            'privateKeyPath' => $server->privateKeyPath,
            'provider' => $server->provider,
            'dropletId' => $server->dropletId,
            'instanceId' => $server->instanceId,
        ];
    }

    /**
     * Hydrate a ServerDTO from inventory data.
     *
     * @param array<string, mixed> $data
     */
    private function hydrateServerDTO(array $data): ServerDTO
    {
        $name = $data['name'] ?? '';
        $host = $data['host'] ?? '';
        $port = $data['port'] ?? 22;
        $username = $data['username'] ?? 'root';
        $privateKeyPath = $data['privateKeyPath'] ?? null;
        $provider = $data['provider'] ?? null;
        $dropletId = $data['dropletId'] ?? null;
        $instanceId = $data['instanceId'] ?? null;

        return new ServerDTO(
            name: is_string($name) ? $name : '',
            host: is_string($host) ? $host : '',
            port: is_int($port) ? $port : 22,
            username: is_string($username) ? $username : 'root',
            privateKeyPath: is_string($privateKeyPath) ? $privateKeyPath : null,
            provider: is_string($provider) ? $provider : null,
            dropletId: is_int($dropletId) ? $dropletId : null,
            instanceId: is_string($instanceId) ? $instanceId : null,
        );
    }
}
