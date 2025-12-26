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
    // Codename Mappings
    // ----

    private const UBUNTU_CODENAMES = [
        '20.04' => 'Focal Fossa',
        '22.04' => 'Jammy Jellyfish',
        '24.04' => 'Noble Numbat',
        '26.04' => 'TBD',
    ];

    private const DEBIAN_CODENAMES = [
        '10' => 'Buster',
        '11' => 'Bullseye',
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
            self::UBUNTU => "{$this->displayName()} {$version} LTS ({$codename})",
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
