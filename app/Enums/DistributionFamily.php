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

}
