<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Site;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\DTOs\SiteDTO;
use Bigpixelrocket\DeployerPHP\Traits\PlaybooksTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
use Bigpixelrocket\DeployerPHP\Traits\SitesTrait;
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
            ->addOption('php-version', null, InputOption::VALUE_REQUIRED, 'PHP version to use');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->heading('Add New Site');

        //
        // Select server
        // ----

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        $this->displayServerDeets($server);

        //
        // Get server info (verifies SSH connection and validates distribution & permissions)
        // ----

        $info = $this->serverInfo($server);

        if (is_int($info)) {
            return $info;
        }

        [
            'distro' => $distro,
            'permissions' => $permissions,
        ] = $info;

        /** @var string $distro */
        /** @var string $permissions */

        //
        // Validate server is ready for site provisioning
        // ----

        $validationResult = $this->validateServerReady($info);

        if (is_int($validationResult)) {
            return $validationResult;
        }

        //
        // Select PHP version
        // ----

        $phpVersion = $this->selectPhpVersion($info);

        if (is_int($phpVersion)) {
            return $phpVersion;
        }

        //
        // Gather site details
        // ----

        $siteInfo = $this->gatherSiteInfo();

        if ($siteInfo === null) {
            return Command::FAILURE;
        }

        [
            'domain' => $domain,
            'repo' => $repo,
            'branch' => $branch,
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

        $result = $this->executePlaybook(
            $server,
            'site-add',
            'Provisioning site...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SITE_DOMAIN' => $domain,
                'DEPLOYER_PHP_VERSION' => $phpVersion,
            ],
            true
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

        $this->io->writeln([
            '',
            'Next steps:',
            '  • Site is accessible at <fg=cyan>https://' . $domain . '</>',
            '  • Update <fg=cyan>DNS records</> to point ' . $domain . ' to <fg=cyan>' . $server->host . '</>',
            '  • Deploy your application with <fg=cyan>site:deploy</>',
            '',
        ]);

        //
        // Show command replay
        // ----

        $this->showCommandReplay('site:add', [
            'domain' => $domain,
            'repo' => $repo,
            'branch' => $branch,
            'server' => $server->name,
            'php-version' => $phpVersion,
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
            $this->io->writeln([
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
     * @return array{domain: string, repo: string, branch: string}|null
     */
    protected function gatherSiteInfo(): ?array
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

        return [
            'domain' => $domain,
            'repo' => $repo,
            'branch' => $branch,
        ];
    }
}
