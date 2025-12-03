<?php

declare(strict_types=1);

namespace Deployer\Enums;

/**
 * Distribution families for server provisioning.
 *
 * Groups distributions by their package management and system architecture.
 */
enum DistributionFamily: string
{
    case DEBIAN = 'debian';

    /**
     * Get all family names as array.
     *
     * @return array<string>
     */
    public static function names(): array
    {
        return array_map(fn (self $family) => $family->value, self::cases());
    }
}
