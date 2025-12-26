<?php

declare(strict_types=1);

namespace DeployerPHP\Services\Do;

use DigitalOceanV2\Entity\Droplet as DropletEntity;
use DigitalOceanV2\Exception\ResourceNotFoundException;

/**
 * DigitalOcean droplet management service.
 *
 * Handles creating, destroying, and monitoring droplets.
 */
class DoDropletService extends BaseDoService
{
    /**
     * Create a new droplet with the specified configuration.
     *
     * @param string $name Droplet name
     * @param string $region Region slug (e.g., nyc3)
     * @param string $size Size slug (e.g., s-1vcpu-1gb)
     * @param string $image Image slug or ID (e.g., ubuntu-22-04-x64)
     * @param array<int, int> $sshKeys SSH key IDs
     * @param bool $backups Enable backups
     * @param bool $monitoring Enable monitoring
     * @param bool $ipv6 Enable IPv6
     * @param string|null $vpcUuid VPC UUID (null for default)
     *
     * @return array{id: int, name: string, status: string} Droplet data
     *
     * @throws \RuntimeException If creation fails
     */
    public function createDroplet(
        string $name,
        string $region,
        string $size,
        string $image,
        array $sshKeys = [],
        bool $backups = false,
        bool $monitoring = false,
        bool $ipv6 = false,
        ?string $vpcUuid = null
    ): array {
        $client = $this->getAPI();

        try {
            $dropletApi = $client->droplet();

            // Prepare VPC parameter (API expects string|bool, false for default)
            $vpcParam = $vpcUuid ?? false;

            /** @var DropletEntity $droplet */
            $droplet = $dropletApi->create(
                $name,
                $region,
                $size,
                $image,
                $backups,
                $ipv6,
                $vpcParam, // VPC UUID or false for default
                $sshKeys,
                '', // user_data
                $monitoring,
                [], // volumes
            );

            return [
                'id' => $droplet->id,
                'name' => $droplet->name,
                'status' => $droplet->status,
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to create droplet: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the current status of a droplet.
     *
     * @throws \RuntimeException If status check fails
     */
    public function getDropletStatus(int $dropletId): string
    {
        $client = $this->getAPI();

        try {
            $dropletApi = $client->droplet();

            /** @var DropletEntity $droplet */
            $droplet = $dropletApi->getById($dropletId);

            return $droplet->status;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to get droplet status: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Wait for a droplet to become active.
     *
     * @param int $dropletId Droplet ID
     * @param int $timeoutSeconds Maximum time to wait (default: 300 = 5 minutes)
     * @param int $pollIntervalSeconds Time between status checks (default: 2)
     *
     * @throws \RuntimeException If timeout is reached or polling fails
     */
    public function waitForDropletReady(
        int $dropletId,
        int $timeoutSeconds = 300,
        int $pollIntervalSeconds = 2
    ): void {
        $startTime = time();

        while (true) {
            $status = $this->getDropletStatus($dropletId);

            if ('active' === $status) {
                return;
            }

            if ((time() - $startTime) >= $timeoutSeconds) {
                throw new \RuntimeException(
                    "Timeout waiting for droplet (ID: {$dropletId}) to become active (current status: {$status})"
                );
            }

            sleep($pollIntervalSeconds);
        }
    }

    /**
     * Get the public IPv4 address of a droplet.
     *
     * @throws \RuntimeException If IP retrieval fails or no public IP found
     */
    public function getDropletIp(int $dropletId): string
    {
        $client = $this->getAPI();

        try {
            $dropletApi = $client->droplet();

            /** @var DropletEntity $droplet */
            $droplet = $dropletApi->getById($dropletId);

            // Find public IPv4 network
            foreach ($droplet->networks as $network) {
                if ('public' === $network->type && 4 === $network->version) {
                    return $network->ipAddress;
                }
            }

            throw new \RuntimeException('No public IPv4 address found for droplet');
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to get droplet IP: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Destroy a droplet by ID.
     *
     * Silently succeeds if droplet doesn't exist (404).
     *
     * @param int $dropletId Droplet ID to destroy
     *
     * @throws \RuntimeException If destruction fails (non-404 errors)
     */
    public function destroyDroplet(int $dropletId): void
    {
        $client = $this->getAPI();

        try {
            $dropletApi = $client->droplet();
            $dropletApi->remove($dropletId);
        } catch (ResourceNotFoundException) {
            // Already deleted - silently succeed
            return;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to destroy droplet: {$e->getMessage()}", 0, $e);
        }
    }
}
