<?php

declare(strict_types=1);

namespace PHPDeployer\Console\Site;

use PHPDeployer\Contracts\BaseCommand;
use PHPDeployer\DTOs\SiteDTO;
use PHPDeployer\Traits\PlaybooksTrait;
use PHPDeployer\Traits\ServersTrait;
use PHPDeployer\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:add',
    description: 'Set up a new site on the server and add it to the inventory'
)]
class SiteAddCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain name')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'Git repository URL')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Git branch name')
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name')
            ->addOption('php-version', null, InputOption::VALUE_REQUIRED, 'PHP version to use')
            ->addOption('www-mode', null, InputOption::VALUE_REQUIRED, 'WWW handling mode (redirect-to-root, redirect-to-www)');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Add New Site');

        //
        // Select server
        // ----

        $server = $this->selectServer();

        if (is_int($server) || $server->info === null) {
            return Command::FAILURE;
        }

        [
            'distro' => $distro,
            'permissions' => $permissions,
        ] = $server->info;

        /** @var string $distro */
        /** @var string $permissions */

        //
        // Validate server is ready for site provisioning
        // ----

        $validationResult = $this->validateServerReady($server->info);

        if (is_int($validationResult)) {
            return $validationResult;
        }

        //
        // Gather site details
        // ----

        $siteInfo = $this->gatherSiteInfo($server->info);

        if ($siteInfo === null) {
            return Command::FAILURE;
        }

        [
            'domain' => $domain,
            'repo' => $repo,
            'branch' => $branch,
            'phpVersion' => $phpVersion,
            'wwwMode' => $wwwMode,
        ] = $siteInfo;

        //
        // Display site details
        // ----

        $site = new SiteDTO(
            domain: $domain,
            repo: $repo,
            branch: $branch,
            server: $server->name
        );

        $this->displaySiteDeets($site);

        //
        // Provision site on server
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'site-add',
            'Provisioning site...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SITE_DOMAIN' => $domain,
                'DEPLOYER_PHP_VERSION' => $phpVersion,
                'DEPLOYER_WWW_MODE' => $wwwMode,
            ]
        );

        if (is_int($result)) {
            return $result;
        }

        $this->yay('Site provisioned successfully');

        //
        // Save to inventory
        // ----

        try {
            $this->sites->create($site);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay('Site added to inventory');

        //
        // Display next steps
        // ----

        $displayUrl = ($wwwMode === 'redirect-to-www')
            ? 'http://www.' . $domain
            : 'http://' . $domain;

        $this->out([
            'Next steps:',
            '  • Site is accessible at <fg=cyan>' . $displayUrl . '</>',
            '  • Update <fg=cyan>DNS records</>:',
            '      - Point <fg=cyan>@</> (root) to <fg=cyan>' . $server->host . '</>',
            '      - Point <fg=cyan>www</> to <fg=cyan>' . $server->host . '</>',
            '  • Run <fg=cyan>site:https</> to enable HTTPS once you have your DNS records set up',
            '  • Deploy your application with <fg=cyan>site:deploy</>',
            '',
        ]);

        //
        // Show command replay
        // ----

        $this->commandReplay('site:add', [
            'domain' => $domain,
            'repo' => $repo,
            'branch' => $branch,
            'server' => $server->name,
            'php-version' => $phpVersion,
            'www-mode' => $wwwMode,
        ]);

        return Command::SUCCESS;
    }

    //
    // Validation
    // ----

    /**
     * Validate that server is ready for site provisioning.
     *
     * Checks for:
     * - Caddy web server installed
     * - PHP installed
     *
     * @param array<string, mixed> $info Server information from serverInfo()
     * @return int|null Returns Command::FAILURE if validation fails, null if successful
     */
    private function validateServerReady(array $info): ?int
    {
        // Check if Caddy is installed
        $caddyInstalled = isset($info['caddy']) && is_array($info['caddy']) && ($info['caddy']['available'] ?? false) === true;

        // Check if PHP is installed
        $phpInstalled = isset($info['php']) && is_array($info['php']) && isset($info['php']['versions']) && is_array($info['php']['versions']) && count($info['php']['versions']) > 0;

        if (!$caddyInstalled || !$phpInstalled) {
            $this->nay('Looks like the server was not installed as expected');
            $this->out([
                'Run <fg=cyan>server:install</> to install required software.',
                '',
            ]);

            return Command::FAILURE;
        }

        return null;
    }

    /**
     * Select PHP version to use for the site.
     *
     * If multiple PHP versions are installed, prompts user to select.
     * If only one version is installed, uses that automatically.
     *
     * @param array<string, mixed> $info Server information from serverInfo()
     * @return string|int Returns PHP version string or Command::FAILURE on error
     */
    private function selectPhpVersion(array $info): string|int
    {
        // Extract installed PHP versions
        $installedPhpVersions = [];
        if (isset($info['php']) && is_array($info['php']) && isset($info['php']['versions']) && is_array($info['php']['versions'])) {
            foreach ($info['php']['versions'] as $version) {
                // Handle both new format (array with version/extensions) and old format (string)
                if (is_array($version) && isset($version['version'])) {
                    /** @var string $versionStr */
                    $versionStr = $version['version'];
                    $installedPhpVersions[] = $versionStr;
                } elseif (is_string($version) || is_numeric($version)) {
                    $installedPhpVersions[] = (string) $version;
                }
            }
        }

        if (empty($installedPhpVersions)) {
            $this->nay('No PHP versions found on server');

            return Command::FAILURE;
        }

        // If only one version, use it automatically
        if (count($installedPhpVersions) === 1) {
            return $installedPhpVersions[0];
        }

        // Multiple versions available - prompt user to select
        rsort($installedPhpVersions, SORT_NATURAL); // Newest first

        /** @var array{default?: string|int|float}|null $phpInfo */
        $phpInfo = $info['php'] ?? null;
        $defaultVersion = is_array($phpInfo) ? ($phpInfo['default'] ?? null) : null;
        $defaultVersionStr = $defaultVersion !== null ? (string) $defaultVersion : $installedPhpVersions[0];

        $phpVersion = (string) $this->io->getOptionOrPrompt(
            'php-version',
            fn () => $this->io->promptSelect(
                label: 'PHP version for this site:',
                options: $installedPhpVersions,
                default: $defaultVersionStr
            )
        );

        // Validate CLI-provided version exists in available versions
        if (!in_array($phpVersion, $installedPhpVersions, true)) {
            $this->nay(
                "PHP version {$phpVersion} is not installed on this server. Available: " . implode(', ', $installedPhpVersions)
            );

            return Command::FAILURE;
        }

        return $phpVersion;
    }

    // ----
    // Helpers
    // ----

    /**
     * Gather site details from user input or CLI options.
     *
     * @param array<string, mixed> $info Server information from serverInfo()
     * @return array{domain: string, repo: string, branch: string, phpVersion: string, wwwMode: string}|null
     */
    protected function gatherSiteInfo(array $info): ?array
    {
        /** @var string|null $domain */
        $domain = $this->io->getValidatedOptionOrPrompt(
            'domain',
            fn ($validate) => $this->io->promptText(
                label: 'Domain name:',
                placeholder: 'example.com',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateSiteDomain($value)
        );

        if ($domain === null) {
            return null;
        }

        // Normalize immediately after input
        $domain = $this->normalizeDomain($domain);

        //
        // Determine WWW handling
        // ----

        $wwwModes = [
            'redirect-to-root' => 'Redirect www to non-www',
            'redirect-to-www' => 'Redirect non-www to www',
        ];

        /** @var string|null $wwwMode */
        $wwwMode = $this->io->getValidatedOptionOrPrompt(
            'www-mode',
            fn ($validate) => $this->io->promptSelect(
                label: "How should 'www.{$domain}' be handled?",
                options: $wwwModes,
                default: 'redirect-to-root',
                validate: $validate
            ),
            fn ($value) => in_array($value, array_keys($wwwModes), true)
                ? null
                : sprintf("Invalid WWW mode '%s'. Allowed: %s", is_scalar($value) ? $value : gettype($value), implode(', ', array_keys($wwwModes)))
        );

        if ($wwwMode === null) {
            return null;
        }

        //
        // Gather git details
        // ----

        $defaultRepo = $this->git->detectRemoteUrl() ?? '';

        /** @var string|null $repo */
        $repo = $this->io->getValidatedOptionOrPrompt(
            'repo',
            fn ($validate) => $this->io->promptText(
                label: 'Git repository URL:',
                placeholder: 'git@github.com:user/repo.git',
                default: $defaultRepo,
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateSiteRepo($value)
        );

        if ($repo === null) {
            return null;
        }

        $defaultBranch = $this->git->detectCurrentBranch() ?? 'main';

        /** @var string|null $branch */
        $branch = $this->io->getValidatedOptionOrPrompt(
            'branch',
            fn ($validate) => $this->io->promptText(
                label: 'Git branch:',
                placeholder: $defaultBranch,
                default: $defaultBranch,
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateSiteBranch($value)
        );

        if ($branch === null) {
            return null;
        }

        //
        // Select PHP version
        // ----

        $phpVersion = $this->selectPhpVersion($info);

        if (is_int($phpVersion)) {
            return null;
        }

        return [
            'domain' => $domain,
            'repo' => $repo,
            'branch' => $branch,
            'phpVersion' => $phpVersion,
            'wwwMode' => $wwwMode,
        ];
    }
}
