<?php

declare(strict_types=1);

namespace Deployer\Services;

use Aws\Sdk;
use Deployer\Services\Aws\AwsAccountService;
use Deployer\Services\Aws\AwsInstanceService;
use Deployer\Services\Aws\AwsKeyService;
use Deployer\Services\Aws\AwsSecurityGroupService;

/**
 * AWS API facade service.
 *
 * Provides access to specialized AWS services through a unified interface.
 */
class AwsService
{
    private ?Sdk $sdk = null;

    private ?string $region = null;

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(
        public readonly AwsAccountService $account,
        public readonly AwsKeyService $key,
        public readonly AwsInstanceService $instance,
        public readonly AwsSecurityGroupService $securityGroup,
    ) {
    }

    //
    // API Initialization
    // ----

    /**
     * Single function to initialize the AWS SDK and verify authentication.
     *
     * Must be called before making any API calls.
     *
     * @param string $accessKeyId AWS Access Key ID
     * @param string $secretAccessKey AWS Secret Access Key
     * @param string $region AWS region (e.g., us-east-1)
     *
     * @throws \RuntimeException If authentication fails or API is unreachable
     */
    public function initialize(string $accessKeyId, string $secretAccessKey, string $region): void
    {
        $this->region = $region;
        $this->initializeSdk($accessKeyId, $secretAccessKey, $region);
        $this->verifyAuthentication();
    }

    /**
     * Get the configured AWS region.
     */
    public function getRegion(): string
    {
        if (null === $this->region) {
            throw new \RuntimeException('AWS region not set. Call initialize() first.');
        }

        return $this->region;
    }

    /**
     * Initialize the AWS SDK with credentials.
     */
    private function initializeSdk(string $accessKeyId, string $secretAccessKey, string $region): void
    {
        if ('' === $accessKeyId || '' === $secretAccessKey) {
            throw new \RuntimeException(
                'AWS credentials not set. '.
                'Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY in your environment.'
            );
        }

        $this->sdk = new Sdk([
            'region' => $region,
            'version' => 'latest',
            'credentials' => [
                'key' => $accessKeyId,
                'secret' => $secretAccessKey,
            ],
        ]);

        // Distribute SDK to all sub-services
        $this->account->setSdk($this->sdk);
        $this->account->setRegion($region);
        $this->key->setSdk($this->sdk);
        $this->key->setRegion($region);
        $this->instance->setSdk($this->sdk);
        $this->instance->setRegion($region);
        $this->securityGroup->setSdk($this->sdk);
        $this->securityGroup->setRegion($region);
    }

    /**
     * Verify AWS authentication using STS GetCallerIdentity.
     *
     * @throws \RuntimeException If authentication fails
     */
    private function verifyAuthentication(): void
    {
        if (null === $this->sdk) {
            throw new \RuntimeException('AWS SDK not initialized.');
        }

        try {
            $sts = $this->sdk->createSts([
                'region' => $this->region,
            ]);

            // GetCallerIdentity is the lightest way to verify credentials
            $sts->getCallerIdentity();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to authenticate with AWS: ' . $e->getMessage(), 0, $e);
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
