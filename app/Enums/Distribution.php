<?php

declare(strict_types=1);

namespace DeployerPHP\Enums;

/**
 * Supported Linux distributions.
 *
 * Provides centralized distribution configuration and business logic.
 */
enum Distribution: string
{
    case UBUNTU = 'ubuntu';

    // ----
    // Version Support
    // ----

    /**
     * Minimum supported Ubuntu version.
     */
    private const MIN_UBUNTU_VERSION = '24.04';

    // ----
    // Codename Mappings
    // ----

    private const UBUNTU_CODENAMES = [
        '24.04' => 'Noble Numbat',
        '26.04' => 'TBD',
    ];

    // ----
    // Display Methods
    // ----

    /**
     * Get human-readable display name.
     */
    public function displayName(): string
    {
        return 'Ubuntu';
    }

    /**
     * Get codename for a version.
     */
    public function codename(string $version): string
    {
        return self::UBUNTU_CODENAMES[$version] ?? 'LTS';
    }

    /**
     * Format version for display.
     */
    public function formatVersion(string $version): string
    {
        $codename = $this->codename($version);

        return $this->isUbuntuLts($version)
            ? "{$this->displayName()} {$version} LTS ({$codename})"
            : "{$this->displayName()} {$version} ({$codename})";
    }

    // ----
    // Server Configuration
    // ----

    /**
     * Get default SSH username for this distribution.
     */
    public function defaultSshUsername(): string
    {
        return 'ubuntu';
    }

    // ----
    // Version Validation
    // ----

    /**
     * Check if a version is supported for this distribution.
     *
     * Ubuntu only supports LTS versions (24.04+). LTS releases follow a
     * predictable pattern: even years with .04 suffix (24.04, 26.04, 28.04...).
     * Ondřej PHP PPA only publishes packages for LTS releases.
     */
    public function isValidVersion(string $version): bool
    {
        return $this->isUbuntuLts($version)
            && version_compare($version, self::MIN_UBUNTU_VERSION, '>=');
    }

    /**
     * Check if a version string matches the Ubuntu LTS pattern.
     *
     * Ubuntu LTS releases follow a predictable pattern: even years with .04 suffix.
     * Examples: 24.04, 26.04, 28.04 are LTS; 25.04, 25.10 are not.
     */
    private function isUbuntuLts(string $version): bool
    {
        // Pattern: YY.04 where YY is even (04, 06, 08... 22, 24, 26...)
        if (1 !== preg_match('/^(\d{2})\.04$/', $version, $matches)) {
            return false;
        }

        $year = (int) $matches[1];

        return 0 === $year % 2;
    }

    /**
     * Get human-readable description of supported versions.
     */
    public function supportedVersions(): string
    {
        return self::MIN_UBUNTU_VERSION . ' LTS or newer LTS releases';
    }

    // ----
    // Slug Methods
    // ----

    /**
     * Build an OS slug from this distribution and a version string.
     *
     * Example: Distribution::UBUNTU->toSlug('24.04') → 'ubuntu-24.04'
     */
    public function toSlug(string $version): string
    {
        return $this->value . '-' . $version;
    }

    /**
     * Parse an OS slug into its distribution and version.
     *
     * Returns null if the slug format is invalid or the distribution is unrecognised.
     *
     * @return array{0: self, 1: string}|null
     */
    public static function fromSlug(string $slug): ?array
    {
        $prefix = self::UBUNTU->value . '-';
        if (!str_starts_with($slug, $prefix)) {
            return null;
        }

        $version = substr($slug, strlen($prefix));
        if ('' === $version) {
            return null;
        }

        return [self::UBUNTU, $version];
    }

    // ----
    // Static Helpers
    // ----

    /**
     * Get all distribution slugs as array.
     *
     * @return array<string>
     */
    public static function slugs(): array
    {
        return [self::UBUNTU->value];
    }
}
