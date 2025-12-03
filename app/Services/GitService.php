<?php

declare(strict_types=1);

namespace Deployer\Services;

/**
 * Git operations service.
 *
 * Provides utilities for detecting git repository information.
 */
final readonly class GitService
{
    public function __construct(
        private ProcessService $proc,
        private FilesystemService $fs,
    ) {
    }

    //
    // Git Detection
    // ----

    /**
     * Detect git remote origin URL from a working directory.
     *
     * @param string|null $workingDir Working directory to run git command in (defaults to current)
     * @return string|null The remote URL, or null if not in a git repo or command fails
     */
    public function detectRemoteUrl(?string $workingDir = null): ?string
    {
        return $this->runGitCommand(
            ['git', 'config', '--get', 'remote.origin.url'],
            $workingDir
        );
    }

    /**
     * Detect current git branch name from a working directory.
     *
     * @param string|null $workingDir Working directory to run git command in (defaults to current)
     * @return string|null The branch name, or null if not in a git repo or command fails
     */
    public function detectCurrentBranch(?string $workingDir = null): ?string
    {
        return $this->runGitCommand(
            ['git', 'rev-parse', '--abbrev-ref', 'HEAD'],
            $workingDir
        );
    }

    //
    // Remote Repository
    // ----

    /**
     * Check if files exist in a remote git repository without full clone.
     *
     * Uses shallow clone with depth=1 to minimize data transfer.
     *
     * @param string $repo Git repository URL
     * @param string $branch Branch to check
     * @param list<string> $paths File paths to check (relative to repo root)
     * @return array<string, bool> Map of path => exists
     * @throws \RuntimeException If git operations fail
     */
    public function checkRemoteFilesExist(string $repo, string $branch, array $paths): array
    {
        $tempDir = sys_get_temp_dir().'/deployer-git-check-'.bin2hex(random_bytes(8));

        try {
            // Shallow clone with depth=1 to minimize data transfer
            $process = $this->proc->run(
                ['git', 'clone', '--depth', '1', '--branch', $branch, '--single-branch', $repo, $tempDir],
                sys_get_temp_dir(),
                30.0
            );

            if (! $process->isSuccessful()) {
                throw new \RuntimeException(
                    "Failed to access git repository '{$repo}' branch '{$branch}': ".trim($process->getErrorOutput())
                );
            }

            // Check each path
            $results = [];
            foreach ($paths as $path) {
                $fullPath = $tempDir.'/'.ltrim($path, '/');
                $results[$path] = $this->fs->exists($fullPath);
            }

            return $results;
        } finally {
            // Clean up temp directory
            if ($this->fs->isDirectory($tempDir)) {
                $this->fs->remove($tempDir);
            }
        }
    }

    //
    // Helpers
    // ----

    /**
     * Run a git command and return trimmed output or null on failure.
     *
     * @param list<string> $cmd Git command and arguments
     * @param string|null $workingDir Working directory (defaults to current)
     * @return string|null Command output or null on failure
     */
    private function runGitCommand(array $cmd, ?string $workingDir): ?string
    {
        try {
            $cwd = $workingDir ?? getcwd();
            if ($cwd === false) {
                return null;
            }

            $process = $this->proc->run($cmd, $cwd, 2.0);

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }

            return null;
        } catch (\Exception) {
            return null;
        }
    }
}
