<?php

declare(strict_types=1);

namespace DeployerPHP\Services\Do;

use DeployerPHP\Services\RetryService;
use DigitalOceanV2\Client;

/**
 * Base class for DigitalOcean API services.
 *
 * Provides common API client management for all DigitalOcean services.
 */
abstract class BaseDoService
{
    public function __construct(
        protected readonly RetryService $retry,
    ) {
    }

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
        if (null === $this->api) {
            throw new \RuntimeException('DigitalOcean API client not configured. Call setAPI() first.');
        }

        return $this->api;
    }

    //
    // Retry Helpers
    // ----

    /**
     * Determine whether a DigitalOcean exception is transient and safe to retry.
     */
    protected function isRetryableDoException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'too many requests')
            || str_contains($message, 'rate limit')
            || str_contains($message, '429')
            || str_contains($message, 'service unavailable')
            || str_contains($message, 'gateway timeout')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'internal server error');
    }

    /**
     * Execute a DigitalOcean operation with retry/backoff.
     *
     * @template T
     * @param callable(): T $attemptCallback
     * @param callable(\Throwable): bool|null $shouldRetry
     *
     * @return T
     */
    protected function withDoRetry(
        callable $attemptCallback,
        string $operationDescription,
        int $retryAttempts = 4,
        int $retryDelaySeconds = 1,
        ?callable $shouldRetry = null
    ): mixed {
        $retryGuard = $shouldRetry ?? $this->isRetryableDoException(...);

        return $this->retry->run(
            attemptCallback: $attemptCallback,
            operationDescription: $operationDescription,
            retryAttempts: $retryAttempts,
            retryDelaySeconds: $retryDelaySeconds,
            shouldRetry: $retryGuard,
        );
    }
}
