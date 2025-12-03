<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\DTOs\SiteDTO;

trait SiteSharedPathsTrait
{
    // ----
    // Shared Path Helpers
    // ----

    private function normalizeRelativePath(string $path): ?string
    {
        $cleaned = trim(str_replace('\\', '/', $path));
        $cleaned = preg_replace('#/+#', '/', $cleaned);

        if ($cleaned === null) {
            $this->nay('Failed to process path. Please check the path format.');

            return null;
        }

        $cleaned = ltrim($cleaned, '/');

        if ($cleaned === '' || str_contains($cleaned, '..')) {
            $this->nay('Remote filename must be relative to the shared/ directory and cannot contain "..".');

            return null;
        }

        return $cleaned;
    }

    private function buildSharedPath(SiteDTO $site, string $relative = ''): string
    {
        $sharedRoot = $this->getSiteSharedPath($site);

        if ($relative === '') {
            return $sharedRoot;
        }

        return rtrim((string) $sharedRoot, '/').'/'.ltrim($relative, '/');
    }
}
