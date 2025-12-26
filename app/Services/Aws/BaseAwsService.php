<?php

declare(strict_types=1);

namespace DeployerPHP\Services\Aws;

use Aws\Sdk;

/**
 * Base class for AWS API services.
 *
 * Provides common SDK and region management for all AWS services.
 */
abstract class BaseAwsService
{
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
}
