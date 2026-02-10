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

    /**
     * Normalize a project-relative script path for storage and comparisons.
     *
     * Trims whitespace and removes leading "./" segments.
     */
    protected function normalizeProjectScriptPath(string $path): string
    {
        $path = trim($path);

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $path;
    }

    /**
     * Validate script path input for project-relative script execution.
     *
     * Rules:
     * - string, non-empty
     * - relative path only (no leading slash)
     * - safe charset: [A-Za-z0-9._/-]
     * - no empty segments (no double slashes)
     * - no current directory segments (".")
     * - no parent traversal segments ("..")
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateProjectScriptPathInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'Script path must be a string';
        }

        $normalized = $this->normalizeProjectScriptPath($value);

        if ('' === $normalized) {
            return 'Script path cannot be empty';
        }

        if (str_starts_with((string) $normalized, '/')) {
            return 'Script path must be relative to the project directory';
        }

        if (! preg_match('/^[A-Za-z0-9._\/-]+$/', (string) $normalized)) {
            return 'Script path may only contain letters, numbers, dots, underscores, slashes, and hyphens';
        }

        $segments = explode('/', (string) $normalized);
        foreach ($segments as $segment) {
            if ('' === $segment) {
                return 'Script path cannot contain empty path segments';
            }

            if ('.' === $segment) {
                return 'Script path cannot contain current directory segments (.)';
            }

            if ('..' === $segment) {
                return 'Script path cannot contain parent directory traversal (..)';
            }
        }

        return null;
    }
}
