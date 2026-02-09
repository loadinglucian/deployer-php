<?php

declare(strict_types=1);

namespace DeployerPHP\Services;

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
    // ----

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

    /**
     * Append contents to a file, creating it if it doesn't exist.
     *
     * @throws \RuntimeException If file cannot be written
     */
    public function appendFile(string $path, string $content): void
    {
        $this->fs->appendToFile($path, $content);
    }

    /**
     * Change file permissions.
     */
    public function chmod(string $path, int $mode): void
    {
        $this->fs->chmod($path, $mode);
    }

    /**
     * Remove files or directories.
     *
     * @param string|iterable<string> $files A filename, an array of files, or a \Traversable instance to remove
     * @throws \RuntimeException If removal fails
     */
    public function remove(string|iterable $files): void
    {
        $this->fs->remove($files);
    }

    /**
     * Get file modification time as Unix timestamp.
     *
     * @throws \RuntimeException If modification time cannot be determined
     */
    public function getFileModificationTime(string $path): int
    {
        $mtime = @filemtime($path);
        if ($mtime === false) {
            throw new \RuntimeException("Cannot read file modification time: {$path}");
        }

        return $mtime;
    }

    /**
     * Join path segments into a canonical path.
     */
    public function joinPaths(string ...$paths): string
    {
        return Path::join(...$paths);
    }

    //
    // Gap-Filling Methods (Native PHP Functions)
    // ----

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
     * Get current user home directory.
     *
     * Returns null when no home directory environment variables are available.
     */
    public function getHomeDirectory(): ?string
    {
        $home = getenv('HOME') ?: '';
        if ($home !== '') {
            return Path::canonicalize($home);
        }

        $userProfile = getenv('USERPROFILE') ?: '';
        if ($userProfile !== '') {
            return Path::canonicalize($userProfile);
        }

        $drive = getenv('HOMEDRIVE') ?: '';
        $path = getenv('HOMEPATH') ?: '';
        if ($drive !== '' && $path !== '') {
            return Path::canonicalize($drive . $path);
        }

        return null;
    }

    /**
     * Get operating system temporary directory.
     */
    public function getTempDirectory(): string
    {
        $tmp = sys_get_temp_dir();
        if ($tmp === '') {
            throw new \RuntimeException('Unable to determine temporary directory');
        }

        return Path::canonicalize($tmp);
    }

    /**
     * Get user cache directory for the current operating system.
     *
     * Returns null when user cache location cannot be determined.
     */
    public function getUserCacheDirectory(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $localAppData = getenv('LOCALAPPDATA') ?: '';
            if ($localAppData !== '') {
                return Path::canonicalize($localAppData);
            }

            $appData = getenv('APPDATA') ?: '';
            return $appData !== '' ? Path::canonicalize($appData) : null;
        }

        $home = $this->getHomeDirectory();
        if ($home === null) {
            return null;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            return Path::canonicalize(Path::join($home, 'Library', 'Caches'));
        }

        $xdgCacheHome = getenv('XDG_CACHE_HOME') ?: '';
        if ($xdgCacheHome !== '') {
            return Path::canonicalize($xdgCacheHome);
        }

        return Path::canonicalize(Path::join($home, '.cache'));
    }

    /**
     * Check if path is a directory.
     */
    public function isDirectory(string $path): bool
    {
        return $this->exists($path) && is_dir($path);
    }

    /**
     * Check if path is a regular file.
     */
    public function isFile(string $path): bool
    {
        return $this->exists($path) && is_file($path);
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
     * Check if path is a symbolic link.
     */
    public function isLink(string $path): bool
    {
        return is_link($path);
    }

    /**
     * Create a directory recursively.
     *
     * @throws \RuntimeException If directory cannot be created
     */
    public function mkdir(string $path, int $mode = 0755): void
    {
        $this->fs->mkdir($path, $mode);
    }

    /**
     * List directory contents (excludes . and ..).
     *
     * @return array<int, string> Array of filenames
     *
     * @throws \RuntimeException If directory cannot be read
     */
    public function scanDirectory(string $path): array
    {
        $entries = @scandir($path);
        if (false === $entries) {
            throw new \RuntimeException("Cannot read directory: {$path}");
        }

        return array_values(array_filter($entries, fn ($e) => ! in_array($e, ['.', '..'], true)));
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

        $home = $this->getHomeDirectory();
        if ($home === null) {
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
     * Shorten path by replacing home directory with tilde (~).
     *
     * Inverse of expandPath() - for display purposes.
     */
    public function shortenPath(string $path): string
    {
        if ('' === $path) {
            return $path;
        }

        $home = $this->getHomeDirectory();
        if ($home === null) {
            return $path;
        }

        // Normalize for comparison
        $home = rtrim($home, '/\\');

        if ($path === $home) {
            return '~';
        }

        if (str_starts_with($path, $home . '/') || str_starts_with($path, $home . '\\')) {
            return '~' . substr($path, strlen($home));
        }

        return $path;
    }

    /**
     * Get first existing path from array of candidates.
     * Automatically expands tilde paths before checking existence,
     * but returns the original path (preserving ~) for portable storage.
     *
     * @param array<int, string> $paths Array of file paths to check
     * @return string|null First existing path (original, may contain ~), or null if none exist
     */
    public function getFirstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            try {
                $expandedPath = $this->expandPath($path);
                if ($this->exists($expandedPath)) {
                    return $path;
                }
            } catch (\RuntimeException) {
                // Skip paths that cannot be expanded (e.g., ~ paths when HOME not set)
                continue;
            }
        }

        return null;
    }
}
