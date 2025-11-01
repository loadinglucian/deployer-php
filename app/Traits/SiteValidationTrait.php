<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\Repositories\ServerRepository;
use Bigpixelrocket\DeployerPHP\Repositories\SiteRepository;
use Bigpixelrocket\DeployerPHP\Services\ProcessService;

/**
 * Validation helpers for site configuration.
 *
 * Requires classes using this trait to have ProcessService, ServerRepository, and SiteRepository properties.
 *
 * @property ProcessService $proc
 * @property ServerRepository $servers
 * @property SiteRepository $sites
 */
trait SiteValidationTrait
{
    /**
     * Validate domain format and uniqueness.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDomainInput(mixed $domain): ?string
    {
        if (!is_string($domain)) {
            return 'Domain must be a string';
        }

        // Check format
        $isValid = filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        if (!$isValid) {
            return 'Must be a valid domain name (e.g., example.com, subdomain.example.com)';
        }

        // Check uniqueness
        $existing = $this->sites->findByDomain($domain);
        if ($existing !== null) {
            return "Domain '{$domain}' already exists in inventory";
        }

        return null;
    }

    /**
     * Validate branch name is not empty.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateBranchInput(mixed $branch): ?string
    {
        if (!is_string($branch)) {
            return 'Branch name must be a string';
        }

        if (trim($branch) === '') {
            return 'Branch name cannot be empty';
        }

        return null;
    }

    /**
     * Validate git repository URL format.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateRepoInput(mixed $repo): ?string
    {
        if (!is_string($repo)) {
            return 'Repository URL must be a string';
        }

        if (trim($repo) === '') {
            return 'Repository URL cannot be empty';
        }

        // Basic format check - should start with git@, https://, http://, or ssh://
        $repo = trim($repo);
        $validPrefixes = ['git@', 'https://', 'http://', 'ssh://'];
        $hasValidPrefix = false;

        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($repo, $prefix)) {
                $hasValidPrefix = true;
                break;
            }
        }

        if (!$hasValidPrefix) {
            return 'Repository URL must start with git@, https://, http://, or ssh://';
        }

        return null;
    }

    /**
     * Validate git repository is accessible.
     *
     * @throws \RuntimeException When repository is not accessible
     */
    protected function validateGitRepo(string $repo): void
    {
        try {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new \RuntimeException('Could not determine current working directory');
            }

            $process = $this->proc->run(
                ['git', 'ls-remote', '--exit-code', $repo],
                $cwd,
                10.0
            );

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(
                    "Cannot access git repository '{$repo}'.\n".
                    'Error: '.$process->getErrorOutput()
                );
            }
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to validate git repository '{$repo}'.\n".
                'Error: '.$e->getMessage()
            );
        }
    }

    /**
     * Validate all servers exist in inventory.
     *
     * @param array<int, string> $serverNames
     * @throws \RuntimeException When any server is not found
     */
    protected function validateServers(array $serverNames): void
    {
        if (count($serverNames) === 0) {
            throw new \RuntimeException('At least one server must be selected');
        }

        foreach ($serverNames as $serverName) {
            $server = $this->servers->findByName($serverName);
            if ($server === null) {
                throw new \RuntimeException("Server '{$serverName}' not found in inventory");
            }
        }
    }
}
