<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\DTOs\SiteDTO;

trait SiteSharedPathsTrait
{
    use PathOperationsTrait;

    /**
     * Build full shared path for a site.
     */
    private function buildSharedPath(SiteDTO $site, string $relative = ''): string
    {
        $sharedRoot = $this->getSiteSharedPath($site);

        if ('' === $relative) {
            return $sharedRoot;
        }

        return $this->fs->joinPaths($sharedRoot, $relative);
    }
}
