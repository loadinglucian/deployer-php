<?php

declare(strict_types=1);

namespace DeployerPHP\Services;

use Composer\InstalledVersions;

/**
 * Handles version detection using Composer's InstalledVersions API.
 */
class VersionService
{
    /**
     * Create a VersionService configured with the package name to query.
     *
     * @param string $packageName The Composer package name to query for version information.
     */
    public function __construct(
        private readonly string $packageName = 'loadinglucian/deployer-php'
    ) {
    }

    /**
     * Get version from Composer's InstalledVersions API with 'dev' fallback.
     */
    public function getVersion(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return 'dev';
        }

        try {
            return InstalledVersions::getPrettyVersion($this->packageName) ?? 'dev';
        } catch (\OutOfBoundsException) {
            return 'dev';
        }
    }
}
