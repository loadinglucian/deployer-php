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
    case DEBIAN = 'debian';

    // ----
    // Version Support
    // ----

    /**
     * Minimum supported Ubuntu version.
     */
    private const MIN_UBUNTU_VERSION = '24.04';

    /**
     * Minimum supported Debian version.
     */
    private const MIN_DEBIAN_VERSION = '12';

    // ----
    // Codename Mappings
    // ----

    private const UBUNTU_CODENAMES = [
        '24.04' => 'Noble Numbat',
        '26.04' => 'TBD',
    ];

    private const DEBIAN_CODENAMES = [
        '12' => 'Bookworm',
        '13' => 'Trixie',
        '14' => 'Forky',
    ];

    // ----
    // Display Methods
    // ----

    /**
     * Get human-readable display name.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::UBUNTU => 'Ubuntu',
            self::DEBIAN => 'Debian',
        };
    }

    /**
     * Get codename for a version.
     */
    public function codename(string $version): string
    {
        return match ($this) {
            self::UBUNTU => self::UBUNTU_CODENAMES[$version] ?? 'LTS',
            self::DEBIAN => self::DEBIAN_CODENAMES[$version] ?? 'Stable',
        };
    }

    /**
     * Format version for display.
     */
    public function formatVersion(string $version): string
    {
        $codename = $this->codename($version);

        return match ($this) {
            self::UBUNTU => $this->isUbuntuLts($version)
                ? "{$this->displayName()} {$version} LTS ({$codename})"
                : "{$this->displayName()} {$version} ({$codename})",
            self::DEBIAN => "{$this->displayName()} {$version} ({$codename})",
        };
    }

    // ----
    // Server Configuration
    // ----

    /**
     * Get default SSH username for this distribution.
     */
    public function defaultSshUsername(): string
    {
        return match ($this) {
            self::UBUNTU => 'ubuntu',
            self::DEBIAN => 'admin',
        };
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
     *
     * Debian supports all stable versions 12+.
     */
    public function isValidVersion(string $version): bool
    {
        return match ($this) {
            self::UBUNTU => $this->isUbuntuLts($version)
                && version_compare($version, self::MIN_UBUNTU_VERSION, '>='),
            self::DEBIAN => version_compare($version, self::MIN_DEBIAN_VERSION, '>='),
        };
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
        return match ($this) {
            self::UBUNTU => self::MIN_UBUNTU_VERSION . ' LTS or newer LTS releases',
            self::DEBIAN => self::MIN_DEBIAN_VERSION . ' or newer',
        };
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
        $parts = explode('-', $slug, 2);

        if (2 !== count($parts)) {
            return null;
        }

        [$distro, $version] = $parts;

        $distribution = self::tryFrom($distro);

        if (null === $distribution) {
            return null;
        }

        return [$distribution, $version];
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
        return array_map(fn (self $dist) => $dist->value, self::cases());
    }
}
