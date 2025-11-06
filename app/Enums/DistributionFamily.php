<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Enums;

/**
 * Distribution families for server provisioning.
 *
 * Groups distributions by their package management and system architecture.
 */
enum DistributionFamily: string
{
    case DEBIAN = 'debian';
    case FEDORA = 'fedora';
    case REDHAT = 'redhat';
    case AMAZON = 'amazon';

    /**
     * Get playbook name for this family.
     */
    public function playbookName(): string
    {
        return "server-install-{$this->value}";
    }

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
