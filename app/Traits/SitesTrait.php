<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\DTOs\ServerDTO;
use Deployer\DTOs\SiteDTO;
use Deployer\Repositories\ServerRepository;
use Deployer\Repositories\SiteRepository;
use Deployer\Services\IOService;
use Deployer\Services\ProcessService;
use Deployer\Services\SSHService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable site things.
 *
 * Requires classes using this trait to have IOService, ProcessService, ServerRepository, SiteRepository, and SSHService properties.
 *
 * @property IOService $io
 * @property ProcessService $proc
 * @property ServerRepository $servers
 * @property SiteRepository $sites
 * @property SSHService $ssh
 */
trait SitesTrait
{
    // ----
    // Helpers
    // ----

    //
    // UI
    // ----

    /**
     * Display a warning to add a site if no sites are available. Otherwise, return all sites.
     *
     * @param array<int, SiteDTO>|null $sites Optional pre-fetched sites; if null, fetches from repository
     * @return array<int, SiteDTO>|int Returns array of sites or Command::SUCCESS if no sites available
     */
    protected function ensureSitesAvailable(?array $sites = null): array|int
    {
        //
        // Get all sites

        $allSites = $sites ?? $this->sites->all();

        //
        // Check if no sites are available

        if (0 === count($allSites)) {
            $this->info('No servers found in your inventory:');
            $this->ul('Run <fg=cyan>site:create</> to create a site');

            return Command::SUCCESS;
        }

        return $allSites;
    }

    /**
     * Select a site from inventory by domain option or interactive prompt.
     *
     * @param array<int, SiteDTO>|null $sites Optional pre-fetched sites; if null, fetches from repository
     * @return SiteDTO|int Returns SiteDTO on success, or Command::SUCCESS if empty inventory, or Command::FAILURE if not found
     */
    protected function selectSite(?array $sites = null): SiteDTO|int
    {
        //
        // Get all sites

        $allSites = $this->ensureSitesAvailable($sites);

        if (is_int($allSites)) {
            return $allSites;
        }

        //
        // Extract site domains and prompt for selection

        $siteDomains = array_map(fn (SiteDTO $site) => $site->domain, $allSites);

        $domain = (string) $this->io->getOptionOrPrompt(
            'domain',
            fn () => $this->io->promptSelect(
                label: 'Select site:',
                options: $siteDomains,
            )
        );

        //
        // Find site by domain

        $site = $this->sites->findByDomain($domain);

        if (null === $site) {
            $this->nay("Site '{$domain}' not found in inventory");

            return Command::FAILURE;
        }

        return $site;
    }

    /**
     * Display site details.
     */
    protected function displaySiteDeets(SiteDTO $site): void
    {
        $details = [
            'Domain' => $site->domain,
            'Server' => $site->server,
        ];

        if (null !== $site->repo) {
            $details['Repo'] = $site->repo;
        }

        if (null !== $site->branch) {
            $details['Branch'] = $site->branch;
        }

        $this->displayDeets($details);
        $this->out('───');
    }

    // ----
    // Validation
    // ----

    /**
     * Validate domain format and uniqueness.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateSiteDomain(mixed $domain): ?string
    {
        if (! is_string($domain)) {
            return 'Domain must be a string';
        }

        $domain = $this->normalizeDomain($domain);

        // Check format
        $isValid = false !== filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        if (! $isValid) {
            return 'Must be a valid domain name (e.g., example.com, subdomain.example.com)';
        }

        // Check uniqueness
        $existing = $this->sites->findByDomain($domain);
        if (null !== $existing) {
            return "Domain '{$domain}' already exists in inventory";
        }

        return null;
    }

    /**
     * Normalize domain name (lowercase and strip www.).
     */
    protected function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }

    /**
     * Validate branch name is not empty.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateSiteBranch(mixed $branch): ?string
    {
        if (! is_string($branch)) {
            return 'Branch name must be a string';
        }

        if ('' === trim($branch)) {
            return 'Branch name cannot be empty';
        }

        return null;
    }

    /**
     * Validate git repository URL format.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateSiteRepo(mixed $repo): ?string
    {
        if (! is_string($repo)) {
            return 'Repository URL must be a string';
        }

        if ('' === trim($repo)) {
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

        if (! $hasValidPrefix) {
            return 'Repository URL must start with git@, https://, http://, or ssh://';
        }

        return null;
    }

    /**
     * Validate that site has been added on the server.
     *
     * Checks for:
     * - Site directory structure exists at /home/deployer/sites/{domain}
     * - Caddy configuration file exists
     *
     * @return int|null Returns Command::FAILURE if validation fails, null if successful
     */
    protected function validateSiteAdded(ServerDTO $server, SiteDTO $site): ?int
    {
        try {
            $result = $this->ssh->executeCommand(
                $server,
                sprintf(
                    'test -d /home/deployer/sites/%s && test -f /etc/caddy/conf.d/sites/%s.caddy',
                    escapeshellarg($site->domain),
                    escapeshellarg($site->domain)
                )
            );

            if (0 !== $result['exit_code']) {
                $this->nay("Site '{$site->domain}' has not been created on the server");
                $this->out([
                    'Run <fg=cyan>site:create</> to create the site first.',
                    '',
                ]);

                return Command::FAILURE;
            }
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        return null;
    }

    /**
     * Get the remote root path for a site.
     */
    protected function getSiteRootPath(SiteDTO $site): string
    {
        return '/home/deployer/sites/' . $site->domain;
    }

    /**
     * Get the remote shared directory path for a site.
     */
    protected function getSiteSharedPath(SiteDTO $site): string
    {
        return $this->getSiteRootPath($site) . '/shared';
    }
}
