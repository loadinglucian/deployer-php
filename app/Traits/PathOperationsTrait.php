<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\Exceptions\ValidationException;

trait PathOperationsTrait
{
    // ----
    // Normalization
    // ----

    /**
     * Normalize a relative path.
     *
     * Converts backslashes to forward slashes, collapses multiple slashes,
     * strips leading slash, and rejects empty paths or paths containing "..".
     *
     * @throws ValidationException When path is invalid
     */
    protected function normalizeRelativePath(string $path): string
    {
        $cleaned = trim(str_replace('\\', '/', $path));
        $cleaned = preg_replace('#/+#', '/', $cleaned);

        if (null === $cleaned) {
            throw new ValidationException('Failed to process path. Please check the path format.');
        }

        $cleaned = ltrim($cleaned, '/');

        if ('' === $cleaned || str_contains($cleaned, '..')) {
            throw new ValidationException('Path must be relative and cannot contain "..".');
        }

        return $cleaned;
    }

    // ----
    // Validation
    // ----

    /**
     * Validate path input (string, non-empty).
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
