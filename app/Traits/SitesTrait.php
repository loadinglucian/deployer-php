<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\Builders\SiteServerBuilder;
use DeployerPHP\DTOs\ServerDTO;
use DeployerPHP\DTOs\SiteDTO;
use DeployerPHP\DTOs\SiteServerDTO;
use DeployerPHP\Enums\WwwMode;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Repositories\ServerRepository;
use DeployerPHP\Repositories\SiteRepository;
use DeployerPHP\Services\GitService;
use DeployerPHP\Services\IoService;
use DeployerPHP\Services\ProcessService;
use DeployerPHP\Services\SshService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable site things.
 *
 * Requires classes using this trait to have IoService, ProcessService, ServerRepository, SiteRepository, and SshService properties.
 *
 * @property IoService $io
 * @property ProcessService $proc
 * @property ServerRepository $servers
 * @property SiteRepository $sites
 * @property SshService $ssh
 * @property GitService $git
 */
trait SitesTrait
{
    use DomainValidationTrait;
    use ServersTrait;

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

    /**
     * Build full shared path for a site.
     */
    protected function buildSharedPath(SiteDTO $site, string $relative = ''): string
    {
        $sharedRoot = $this->getSiteSharedPath($site);

        if ('' === $relative) {
            return $sharedRoot;
        }

        return $this->fs->joinPaths($sharedRoot, $relative);
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
     * @param string $directory Directory path (e.g., '.deployer/scripts')
     * @return array<int, string> Script list (empty if none found)
     * @throws \RuntimeException If git operations fail
     */
    protected function getAvailableScripts(SiteDTO $site, string $directory): array
    {
        return $this->listRemoteSiteDirectory($site, $directory);
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
        //
        // If specific site requested via option, validate it exists first

        /** @var string|null $requestedDomain */
        $requestedDomain = $this->io->getOptionValue('domain');

        if (null !== $requestedDomain) {
            $site = $this->sites->findByDomain($requestedDomain);

            if (null === $site) {
                $this->nay("Site '{$requestedDomain}' not found in inventory");

                return Command::FAILURE;
            }

            $this->displaySiteDeets($site);

            return $site;
        }

        //
        // No specific site requested - check if inventory is empty

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

        return SiteServerBuilder::new()
            ->site($site)
            ->server($server)
            ->build();
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
     * Detect whether a domain is a subdomain.
     *
     * Uses two-part ccTLD suffix inference (example.co.uk, example.com.au).
     */
    protected function isSubdomain(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        $labels = explode('.', (string) $domain);
        $labelCount = count($labels);

        // Known second-level suffix labels used with ccTLDs.
        $ccSecondLevelLabels = ['ac', 'co', 'com', 'edu', 'gov', 'net', 'org'];
        $tld = $labels[$labelCount - 1] ?? '';
        $secondLevel = $labels[$labelCount - 2] ?? '';

        $isCcTld = 2 === strlen($tld);
        $isTwoPartSuffix = $isCcTld && in_array($secondLevel, $ccSecondLevelLabels, true);
        $apexLabelCount = $isTwoPartSuffix ? 3 : 2;

        return $labelCount > $apexLabelCount;
    }

    /**
     * Validate WWW mode input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateWwwMode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'WWW mode must be a string';
        }

        if (! WwwMode::isSelectable($value)) {
            return sprintf(
                "Invalid WWW mode '%s'. Allowed: %s",
                $value,
                implode(', ', WwwMode::values(includeUnknown: false))
            );
        }

        return null;
    }

    /**
     * Determine whether a site should have a WWW alias.
     *
     * Subdomains never get a WWW alias, regardless of requested mode.
     */
    protected function hasWww(string $domain, string $wwwMode): bool
    {
        if ($this->isSubdomain($domain)) {
            return false;
        }

        return WwwMode::NONE->value !== $wwwMode;
    }

    /**
     * Validate that site has been added on the server.
     *
     * Checks for:
     * - Site directory structure exists at /home/deployer/sites/{domain}
     * - Nginx configuration file exists
     *
     * @return int|null Returns Command::FAILURE if validation fails, null if successful
     */
    protected function ensureSiteExists(ServerDTO $server, SiteDTO $site): ?int
    {
        try {
            $result = $this->ssh->executeCommand(
                $server,
                sprintf(
                    'test -d /home/deployer/sites/%s && test -f /etc/nginx/sites-available/%s',
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

        // Check for www-only domain before normalization
        if ('www.' === strtolower(trim($domain))) {
            return "Domain cannot be just 'www.'";
        }

        $domain = $this->normalizeDomain($domain);

        // Check format using centralized validation
        $formatError = $this->validateDomainFormat($domain);
        if (null !== $formatError) {
            return $formatError;
        }

        // Check uniqueness in inventory
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
