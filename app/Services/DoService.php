<?php

declare(strict_types=1);

namespace Deployer\Services;

use Deployer\Services\Do\DoAccountService;
use Deployer\Services\Do\DoDropletService;
use Deployer\Services\Do\DoKeyService;
use DigitalOceanV2\Client;

/**
 * DigitalOcean API facade service.
 *
 * Provides access to specialized DigitalOcean services through a unified interface.
 */
class DoService
{
    private ?Client $api = null;

    private ?string $token = null;

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(
        public readonly DoAccountService $account,
        public readonly DoKeyService $key,
        public readonly DoDropletService $droplet,
    ) {
    }

    //
    // API Initialization
    // ----

    /**
     * Single function to initialize the DigitalOcean API and verify authentication.
     *
     * Must be called before making any API calls.
     *
     * @param string $token The DigitalOcean API token
     *
     * @throws \RuntimeException If authentication fails or API is unreachable
     */
    public function initialize(string $token): void
    {
        $this->setToken($token);
        $this->initializeAPI();
        $this->verifyAuthentication();
    }

    /**
     * Set a DigitalOcean API token.
     */
    private function setToken(string $token): void
    {
        $this->token = $token;

        // Reset client every time a new token is set
        $this->api = null;
    }

    /**
     * Initialize and return the DigitalOcean API client.
     *
     * @throws \RuntimeException If API token is not configured
     */
    private function initializeAPI(): Client
    {
        if (null !== $this->api) {
            return $this->api;
        }

        if (null === $this->token || '' === $this->token) {
            throw new \RuntimeException(
                'DigitalOcean API token not set. '.
                'Set API token before making API requests.'
            );
        }

        $this->api = new Client();
        $this->api->authenticate($this->token);

        $this->account->setAPI($this->api);
        $this->key->setAPI($this->api);
        $this->droplet->setAPI($this->api);

        return $this->api;
    }

    /**
     * Verify DigitalOcean API authentication.
     *
     * @throws \RuntimeException If authentication fails or API is unreachable
     */
    private function verifyAuthentication(): void
    {
        $api = $this->initializeAPI();

        try {
            // Use account endpoint to verify token validity
            $api->account()->getUserInformation();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to authenticate with DigitalOcean API: ' . $e->getMessage(), 0, $e);
        }
    }

    //
    // Cache management
    // ----

    /**
     * Check if a cache key exists.
     */
    public function hasCache(string $key): bool
    {
        return isset($this->cache[$key]);
    }

    /**
     * Get a cached value.
     */
    public function getCache(string $key): mixed
    {
        return $this->cache[$key] ?? null;
    }

    /**
     * Set a cache value.
     */
    public function setCache(string $key, mixed $value): void
    {
        $this->cache[$key] = $value;
    }

    /**
     * Clear a specific cache key.
     */
    public function clearCache(string $key): void
    {
        unset($this->cache[$key]);
    }
}
