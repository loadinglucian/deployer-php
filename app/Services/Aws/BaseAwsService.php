<?php

declare(strict_types=1);

namespace DeployerPHP\Services\Aws;

use Aws\Sdk;
use DeployerPHP\Services\RetryService;

/**
 * Base class for AWS API services.
 *
 * Provides common SDK and region management for all AWS services.
 */
abstract class BaseAwsService
{
    public function __construct(
        protected readonly RetryService $retry,
    ) {
    }

    private ?Sdk $sdk = null;

    private ?string $region = null;

    /**
     * Set the AWS SDK instance.
     */
    public function setSdk(Sdk $sdk): void
    {
        $this->sdk = $sdk;
    }

    /**
     * Set the AWS region.
     */
    public function setRegion(string $region): void
    {
        $this->region = $region;
    }

    /**
     * Get the configured AWS SDK.
     *
     * @throws \RuntimeException If SDK not configured
     */
    protected function getSdk(): Sdk
    {
        if (null === $this->sdk) {
            throw new \RuntimeException('AWS SDK not configured. Call setSdk() first.');
        }

        return $this->sdk;
    }

    /**
     * Get the configured AWS region.
     *
     * @throws \RuntimeException If region not configured
     */
    protected function getRegion(): string
    {
        if (null === $this->region) {
            throw new \RuntimeException('AWS region not configured. Call setRegion() first.');
        }

        return $this->region;
    }

    /**
     * Create an EC2 client for the specified region.
     *
     * @param string|null $region Region override (uses default if null)
     */
    protected function createEc2Client(?string $region = null): \Aws\Ec2\Ec2Client
    {
        return $this->getSdk()->createEc2([
            'region' => $region ?? $this->getRegion(),
        ]);
    }

    /**
     * Create an STS client for verifying credentials.
     */
    protected function createStsClient(): \Aws\Sts\StsClient
    {
        return $this->getSdk()->createSts([
            'region' => $this->getRegion(),
        ]);
    }

    /**
     * Create an SSM client for parameter store access.
     *
     * @param string|null $region Region override (uses default if null)
     */
    protected function createSsmClient(?string $region = null): \Aws\Ssm\SsmClient
    {
        return $this->getSdk()->createSsm([
            'region' => $region ?? $this->getRegion(),
        ]);
    }

    /**
     * Create a Route53 client for DNS management.
     *
     * Route53 is a global service, so we always use us-east-1.
     */
    protected function createRoute53Client(): \Aws\Route53\Route53Client
    {
        return $this->getSdk()->createRoute53([
            'region' => 'us-east-1',
        ]);
    }

    //
    // Retry Helpers
    // ----

    /**
     * Determine whether an AWS exception is transient and safe to retry.
     */
    protected function isRetryableAwsException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'throttl')
            || str_contains($message, 'rate exceeded')
            || str_contains($message, 'request limit exceeded')
            || str_contains($message, 'service unavailable')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'internalerror')
            || str_contains($message, 'internal error')
            || str_contains($message, 'request timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'connection reset');
    }

    /**
     * Execute an AWS operation with retry/backoff.
     *
     * @template T
     * @param callable(): T $attemptCallback
     * @param callable(\Throwable): bool|null $shouldRetry
     *
     * @return T
     */
    protected function withAwsRetry(
        callable $attemptCallback,
        string $operationDescription,
        int $retryAttempts = 4,
        int $retryDelaySeconds = 1,
        ?callable $shouldRetry = null
    ): mixed {
        $retryGuard = $shouldRetry ?? $this->isRetryableAwsException(...);

        return $this->retry->run(
            attemptCallback: $attemptCallback,
            operationDescription: $operationDescription,
            retryAttempts: $retryAttempts,
            retryDelaySeconds: $retryDelaySeconds,
            shouldRetry: $retryGuard,
        );
    }
}
