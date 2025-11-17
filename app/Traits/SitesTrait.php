<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\DTOs\SiteDTO;
use Bigpixelrocket\DeployerPHP\Repositories\ServerRepository;
use Bigpixelrocket\DeployerPHP\Repositories\SiteRepository;
use Bigpixelrocket\DeployerPHP\Services\IOService;
use Bigpixelrocket\DeployerPHP\Services\ProcessService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable site things.
 *
 * Requires classes using this trait to have IOService, ProcessService, ServerRepository, and SiteRepository properties.
 *
 * @property IOService $io
 * @property ProcessService $proc
 * @property ServerRepository $servers
 * @property SiteRepository $sites
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

        if (count($allSites) === 0) {
            $this->io->warning('No sites found in inventory');
            $this->io->writeln([
                '',
                'Run <fg=cyan>site:add</> to add a site',
                '',
            ]);

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

        if ($site === null) {
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
            'Source' => 'Git',
            'Repo' => $site->repo,
            'Branch' => $site->branch,
            'Server' => $site->server,
        ];

        $this->io->displayDeets($details);
        $this->io->writeln('');
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
    protected function validateSiteBranch(mixed $branch): ?string
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
    protected function validateSiteRepo(mixed $repo): ?string
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
}
