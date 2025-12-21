<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\DTOs\ServerDTO;
use Deployer\DTOs\SiteDTO;
use Deployer\DTOs\SiteServerDTO;
use Deployer\Exceptions\ValidationException;
use Deployer\Repositories\ServerRepository;
use Deployer\Repositories\SiteRepository;
use Deployer\Services\GitService;
use Deployer\Services\IOService;
use Deployer\Services\ProcessService;
use Deployer\Services\SSHService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable site things.
 *
 * Requires classes using this trait to have IOService, ProcessService, ServerRepository, SiteRepository, and SSHService properties.
 *
 * @mixin ServersTrait
 *
 * @property IOService $io
 * @property ProcessService $proc
 * @property ServerRepository $servers
 * @property SiteRepository $sites
 * @property SSHService $ssh
 * @property GitService $git
 */
trait SitesTrait
{
    // ----
    // Helpers
    // ----

    //
    // Path
    // ----

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

    //
    // Remote
    // ----

    /**
     * Check if specific files exist in site's remote repository.
     *
     * Returns empty array if site has no repo/branch configured.
     *
     * @param SiteDTO $site
     * @param list<string> $paths File paths relative to repo root
     * @return array<string, bool> Map of path => exists boolean
     * @throws \RuntimeException If git operations fail
     */
    protected function checkRemoteSiteFiles(SiteDTO $site, array $paths): array
    {
        if (null === $site->repo || null === $site->branch) {
            return [];
        }

        return $this->git->checkRemoteFilesExist($site->repo, $site->branch, $paths);
    }

    /**
     * Get available scripts from a remote site directory.
     *
     * @param string $directory    Directory path (e.g., '.deployer/crons')
     * @param string $resourceType Human-readable type for messages (e.g., 'cron')
     * @param string $scaffoldCmd  Scaffold command hint (e.g., 'scaffold:crons')
     * @return array<int, string>|int Script list or Command::FAILURE
     */
    protected function getAvailableScripts(
        SiteDTO $site,
        string $directory,
        string $resourceType,
        string $scaffoldCmd
    ): array|int {
        try {
            $scripts = $this->listRemoteSiteDirectory($site, $directory);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $scripts) {
            $this->warn("No {$resourceType} scripts found in repository");
            $this->info("Run <|cyan>{$scaffoldCmd}</> to create some");

            return Command::FAILURE;
        }

        return $scripts;
    }

    /**
     * List files in a directory of site's remote repository.
     *
     * Returns empty array if site has no repo/branch configured or directory doesn't exist.
     *
     * @param SiteDTO $site
     * @param string $directory Directory path relative to repo root
     * @return array<int, string> List of file paths relative to the directory
     * @throws \RuntimeException If git operations fail
     */
    protected function listRemoteSiteDirectory(SiteDTO $site, string $directory): array
    {
        if (null === $site->repo || null === $site->branch) {
            return [];
        }

        return $this->git->listRemoteDirectoryFiles($site->repo, $site->branch, $directory);
    }

    //
    // UI
    // ----

    /**
     * Display site details.
     */
    protected function displaySiteDeets(SiteDTO $site): void
    {
        $details = [
            'Domain' => $site->domain,
            'Server' => $site->server,
            'PHP' => $site->phpVersion,
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

    /**
     * Ensure site has been deployed (has repo/branch configured).
     *
     * @return int|null Command::FAILURE if not deployed, null if OK
     */
    protected function ensureSiteDeployed(SiteDTO $site): ?int
    {
        if (null === $site->repo || null === $site->branch) {
            $this->warn('Site has not been deployed yet');
            $this->info('Run <|cyan>site:deploy</> to deploy the site first');

            return Command::FAILURE;
        }

        return null;
    }

    /**
     * Display a warning to add a site if no sites are available. Otherwise, return all sites.
     *
     * @return array<int, SiteDTO>|int Returns array of sites or Command::SUCCESS if no sites available
     */
    protected function ensureSitesAvailable(): array|int
    {
        if ([] === $this->sites->all()) {
            $this->warn('No sites found in inventory:');
            $this->info('Run <fg=cyan>site:create</> to create one');

            return Command::SUCCESS;
        }

        return $this->sites->all();
    }

    /**
     * Select a site from inventory by domain option or interactive prompt.
     *
     * @return SiteDTO|int Returns SiteDTO on success, or Command::SUCCESS if empty inventory
     */
    protected function selectSiteDeets(): SiteDTO|int
    {
        $allSites = $this->ensureSitesAvailable();

        if (is_int($allSites)) {
            return $allSites;
        }

        //
        // Extract site domains and prompt for selection

        $siteDomains = array_map(fn (SiteDTO $site) => $site->domain, $allSites);

        try {
            /** @var string $domain */
            $domain = $this->io->getValidatedOptionOrPrompt(
                'domain',
                fn ($validate) => $this->io->promptSelect(
                    label: 'Select site:',
                    options: $siteDomains,
                    validate: $validate
                ),
                fn ($value) => $this->validateSiteSelection($value)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        /** @var SiteDTO $site */
        $site = $this->sites->findByDomain($domain);

        $this->displaySiteDeets($site);

        return $site;
    }

    /**
     * Select a site and resolve its server with full info.
     *
     * Combines selectSiteDeets(), displaySiteDeets(), getServerForSite(), and getServerInfo()
     * into a single operation.
     */
    protected function selectSiteDeetsWithServer(): SiteServerDTO|int
    {
        $site = $this->selectSiteDeets();

        if (is_int($site)) {
            return $site;
        }

        $server = $this->getServerForSite($site);

        if (is_int($server)) {
            return $server;
        }

        $server = $this->getServerInfo($server);

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        return new SiteServerDTO($site, $server);
    }

    // ----
    // Validation
    // ----

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
     * Validate that site has been added on the server.
     *
     * Checks for:
     * - Site directory structure exists at /home/deployer/sites/{domain}
     * - Caddy configuration file exists
     *
     * @return int|null Returns Command::FAILURE if validation fails, null if successful
     */
    protected function ensureSiteExists(ServerDTO $server, SiteDTO $site): ?int
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
                $this->warn("Site '{$site->domain}' has not been created on the server");
                $this->info('Run <|cyan>site:create</> to create the site first');

                return Command::FAILURE;
            }
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
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
        if (! is_string($branch)) {
            return 'Branch name must be a string';
        }

        if ('' === trim($branch)) {
            return 'Branch name cannot be empty';
        }

        return null;
    }

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
     * Validate site selection exists in inventory.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateSiteSelection(mixed $domain): ?string
    {
        if (! is_string($domain)) {
            return 'Domain must be a string';
        }

        if (null === $this->sites->findByDomain($domain)) {
            return "Site '{$domain}' not found in inventory";
        }

        return null;
    }
}
