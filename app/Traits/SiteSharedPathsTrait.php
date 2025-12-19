<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\DTOs\SiteDTO;
use Deployer\Exceptions\ValidationException;

trait SiteSharedPathsTrait
{
    // ----
    // Helpers
    // ----

    /**
     * Build full shared path for a site.
     */
    private function buildSharedPath(SiteDTO $site, string $relative = ''): string
    {
        $sharedRoot = $this->getSiteSharedPath($site);

        if ($relative === '') {
            return $sharedRoot;
        }

        return rtrim((string) $sharedRoot, '/').'/'.ltrim($relative, '/');
    }

    /**
     * Normalize a relative path for shared directory operations.
     *
     * @throws ValidationException When path is invalid
     */
    private function normalizeRelativePath(string $path): string
    {
        $cleaned = trim(str_replace('\\', '/', $path));
        $cleaned = preg_replace('#/+#', '/', $cleaned);

        if ($cleaned === null) {
            throw new ValidationException('Failed to process path. Please check the path format.');
        }

        $cleaned = ltrim($cleaned, '/');

        if ($cleaned === '' || str_contains($cleaned, '..')) {
            throw new ValidationException('Remote filename must be relative to the shared/ directory and cannot contain "..".');
        }

        return $cleaned;
    }

    // ----
    // Validation
    // ----

    /**
     * Validate path input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validatePathInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'Path must be a string';
        }

        if ('' === trim($value)) {
            return 'Path cannot be empty';
        }

        return null;
    }
}
