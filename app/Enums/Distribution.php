<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Enums;

/**
 * Supported Linux distributions.
 *
 * Provides centralized distribution configuration and business logic.
 */
enum Distribution: string
{
    case UBUNTU = 'ubuntu';
    case DEBIAN = 'debian';
    case FEDORA = 'fedora';
    case CENTOS = 'centos';
    case ROCKY = 'rocky';
    case ALMA = 'alma';
    case RHEL = 'rhel';
    case AMAZON = 'amazon';

    /**
     * Get human-readable display name.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::UBUNTU => 'Ubuntu',
            self::DEBIAN => 'Debian',
            self::FEDORA => 'Fedora',
            self::CENTOS => 'CentOS',
            self::ROCKY => 'Rocky Linux',
            self::ALMA => 'AlmaLinux',
            self::RHEL => 'Red Hat Enterprise Linux',
            self::AMAZON => 'Amazon Linux',
        };
    }

    /**
     * Get distribution family.
     */
    public function family(): DistributionFamily
    {
        return match ($this) {
            self::UBUNTU, self::DEBIAN => DistributionFamily::DEBIAN,
            self::FEDORA => DistributionFamily::FEDORA,
            self::CENTOS, self::ROCKY, self::ALMA, self::RHEL => DistributionFamily::REDHAT,
            self::AMAZON => DistributionFamily::AMAZON,
        };
    }

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
