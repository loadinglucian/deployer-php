<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Services;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Thin wrapper around Symfony Filesystem with gap-filling methods.
 *
 * Provides a mockable interface for all filesystem operations. All services
 * should use this exclusively instead of Symfony Filesystem or native PHP
 * functions directly.
 *
 * @example
 * // Symfony Filesystem wrappers
 * $fs->exists('/path/to/file');
 * $content = $fs->readFile('/path/to/file');
 * $fs->dumpFile('/path/to/file', 'contents');
 *
 * // Gap-filling methods (native PHP functions wrapped)
 * $cwd = $fs->getCwd();
 * $isDir = $fs->isDirectory('/path');
 * $parent = $fs->getParentDirectory(__DIR__, 2);
 */
final readonly class FilesystemService
{
    public function __construct(
        private Filesystem $fs,
    ) {
    }

    //
    // Symfony Filesystem Wrappers
    // -------------------------------------------------------------------------------

    /**
     * Check if a file or directory exists.
     */
    public function exists(string $path): bool
    {
        return $this->fs->exists($path);
    }

    /**
     * Read file contents.
     *
     * @throws \RuntimeException If file cannot be read
     */
    public function readFile(string $path): string
    {
        return $this->fs->readFile($path);
    }

    /**
     * Write contents to a file.
     *
     * @throws \RuntimeException If file cannot be written
     */
    public function dumpFile(string $path, string $content): void
    {
        $this->fs->dumpFile($path, $content);
    }

    //
    // Gap-Filling Methods (Native PHP Functions)
    // -------------------------------------------------------------------------------

    /**
     * Get current working directory.
     *
     * @throws \RuntimeException If current directory cannot be determined
     */
    public function getCwd(): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('Unable to determine current working directory');
        }

        return $cwd;
    }

    /**
     * Check if path is a directory.
     */
    public function isDirectory(string $path): bool
    {
        return $this->exists($path) && is_dir($path);
    }

    /**
     * Get parent directory path.
     *
     * @param int $levels Number of parent directories to traverse (default: 1)
     */
    public function getParentDirectory(string $path, int $levels = 1): string
    {
        if ($levels < 1) {
            throw new \InvalidArgumentException('Levels must be at least 1');
        }

        return dirname($path, $levels);
    }

    /**
     * Expand leading tilde (~) to user's home directory.
     *
     * @throws \RuntimeException If HOME environment variable not found when needed
     */
    public function expandPath(string $path): string
    {
        if ($path === '' || $path[0] !== '~') {
            return $path;
        }

        // Determine home directory (POSIX first, then Windows fallbacks)
        $home = getenv('HOME') ?: '';
        if ($home === '') {
            $home = getenv('USERPROFILE') ?: '';
            if ($home === '') {
                $drive = getenv('HOMEDRIVE') ?: '';
                $hpath = getenv('HOMEPATH') ?: '';
                if ($drive !== '' && $hpath !== '') {
                    $home = $drive . $hpath;
                }
            }
        }
        if ($home === '') {
            throw new \RuntimeException('Could not determine home directory (HOME/USERPROFILE not set)');
        }

        // Only expand "~" and "~/" (or "~\"); leave "~user" untouched
        if ($path === '~') {
            return Path::canonicalize($home);
        }
        if (str_starts_with($path, '~/') || str_starts_with($path, '~\\')) {
            return Path::canonicalize($home . substr($path, 1));
        }
        return $path;
    }

    /**
     * Get first existing path from array of candidates.
     * Automatically expands tilde paths before checking existence.
     *
     * @param array<int, string> $paths Array of file paths to check
     * @return string|null First existing path (expanded), or null if none exist
     */
    public function getFirstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            try {
                $expandedPath = $this->expandPath($path);
                if ($this->exists($expandedPath)) {
                    return $expandedPath;
                }
            } catch (\RuntimeException) {
                // Skip paths that cannot be expanded (e.g., ~ paths when HOME not set)
                continue;
            }
        }

        return null;
    }
}
