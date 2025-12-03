<?php

declare(strict_types=1);

namespace Deployer\Services\DigitalOcean;

use DigitalOceanV2\Client;

/**
 * Base class for DigitalOcean API services.
 *
 * Provides common API client management for all DigitalOcean services.
 */
abstract class BaseDigitalOceanService
{
    private ?Client $api = null;

    /**
     * Set the DigitalOcean API client.
     */
    public function setAPI(Client $api): void
    {
        $this->api = $api;
    }

    /**
     * Get the configured DigitalOcean API client.
     *
     * @throws \RuntimeException If client not configured
     */
    protected function getAPI(): Client
    {
        if ($this->api === null) {
            throw new \RuntimeException('DigitalOcean API client not configured. Call setAPI() first.');
        }

        return $this->api;
    }
}
