<?php

declare(strict_types=1);

namespace Deployer\Console\Site;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\SiteDTO;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:deploy',
    description: 'Deploy a site by running the deployment playbook and hooks'
)]
class SiteDeployCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;

    private const DEFAULT_KEEP_RELEASES = 5;

    /** @var array<int, string> */
    private const REQUIRED_HOOKS = [
        '1-building.sh',
        '2-releasing.sh',
        '3-finishing.sh',
    ];

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Site domain')
            ->addOption('keep-releases', null, InputOption::VALUE_REQUIRED, 'Number of releases to keep (default: 5)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Deploy without confirmation prompt');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Deploy Site');

        //
        // Select site & display details
        // ----

        $site = $this->selectSite();

        if (is_int($site)) {
            return $site;
        }

        $this->displaySiteDeets($site);

        //
        // Check for deployment hooks in remote repository
        // ----

        try {
            $missingHooks = $this->checkRemoteHooksExist($site);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        if ($missingHooks !== []) {
            $this->warn('Missing deployment hooks in repository:');
            foreach ($missingHooks as $hook) {
                $this->out('  • ' . $hook);
            }

            $this->out([
                '  • Run <fg=cyan>scaffold:hooks</> to create them',
                '  • Or continue deployment anyway...',
                '',
            ]);

            $skipConfirm = $this->io->getOptionOrPrompt(
                'yes',
                fn () => $this->io->promptConfirm('Continue deployment anyway?', default: false)
            );

            if (! $skipConfirm) {
                return Command::FAILURE;
            }

            $this->out('');
        }

        //
        // Get server for site
        // ----

        $server = $this->getServerForSite($site);
        if (is_int($server)) {
            return $server;
        }

        //
        // Get server info (verifies SSH and validates distro & permissions)
        // ----

        $server = $this->serverInfo($server);

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
        // Validate site is added on server
        // ----

        $validationResult = $this->validateSiteAdded($server, $site);

        if (is_int($validationResult)) {
            return $validationResult;
        }

        //
        // Resolve deployment parameters
        // ----

        $branch = $site->branch;
        $keepReleases = $this->resolveKeepReleases($input);

        if ($keepReleases === null) {
            return Command::FAILURE;
        }

        $phpVersion = $this->resolvePhpVersion($server->info);
        if ($phpVersion === null) {
            return Command::FAILURE;
        }

        //
        // Confirm deployment
        // ----

        /** @var bool $confirmed */
        $confirmed = $this->io->getOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Deploy now?',
                default: true
            )
        );

        if (! $confirmed) {
            $this->warn('Deployment cancelled.');
            $this->out('');

            return Command::SUCCESS;
        }

        //
        // Execute deployment playbook
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'site-deploy',
            'Deploying site...',
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SITE_DOMAIN' => $site->domain,
                'DEPLOYER_SITE_REPO' => $site->repo,
                'DEPLOYER_SITE_BRANCH' => $branch,
                'DEPLOYER_PHP_VERSION' => (string) $phpVersion,
                'DEPLOYER_KEEP_RELEASES' => (string) $keepReleases,
            ]
        );

        if (is_int($result)) {
            return $result;
        }

        //
        // Display results
        // ----

        $this->yay('Deployment completed');
        $this->displayDeploymentSummary($result, $branch, (string) $phpVersion);

        $this->out([
            'Next steps:',
            '  • Run <fg=cyan>site:shared:push</> to upload shared files (e.g. .env)',
            '  • View deployment logs with <fg=cyan>server:logs</>',
            '',
        ]);

        //
        // Show command replay
        // ----

        $this->commandReplay('site:deploy', [
            'domain' => $site->domain,
            'keep-releases' => $keepReleases,
            'yes' => true,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Display deployment summary details.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayDeploymentSummary(array $result, string $branch, string $phpVersion): void
    {
        $lines = [
            'Branch' => $branch,
            'PHP' => $phpVersion,
        ];

        if (isset($result['release_name']) && is_string($result['release_name'])) {
            $lines['Release'] = $result['release_name'];
        }

        if (isset($result['release_path']) && is_string($result['release_path'])) {
            $lines['Release Path'] = $result['release_path'];
        }

        if (isset($result['current_path']) && is_string($result['current_path'])) {
            $lines['Current Symlink'] = $result['current_path'];
        }

        $this->displayDeets($lines);
        $this->out('');
    }

    private function resolveKeepReleases(InputInterface $input): ?int
    {
        /** @var string|null $value */
        $value = $input->getOption('keep-releases');
        if ($value === null || trim($value) === '') {
            return self::DEFAULT_KEEP_RELEASES;
        }

        if (! ctype_digit($value)) {
            $this->nay('The --keep-releases option must be a positive integer.');

            return null;
        }

        $intValue = (int) $value;
        if ($intValue < 1) {
            $this->nay('The --keep-releases option must be at least 1.');

            return null;
        }

        return $intValue;
    }

    /**
     * Resolve PHP version from server info, prompting user if multiple exist.
     *
     * @param  array<string, mixed>  $info
     */
    private function resolvePhpVersion(array $info): ?string
    {
        $versions = [];
        $phpInfo = $info['php'] ?? null;
        if (is_array($phpInfo) && isset($phpInfo['versions']) && is_array($phpInfo['versions'])) {
            foreach ($phpInfo['versions'] as $version) {
                if (is_array($version) && isset($version['version']) && (is_string($version['version']) || is_numeric($version['version']))) {
                    $versions[] = (string) $version['version'];
                } elseif (is_string($version) || is_numeric($version)) {
                    $versions[] = (string) $version;
                }
            }
        }

        if ($versions === []) {
            $this->nay('No PHP versions found on the server. Run server:install first.');

            return null;
        }

        $default = null;
        if (is_array($phpInfo) && isset($phpInfo['default']) && (is_string($phpInfo['default']) || is_numeric($phpInfo['default']))) {
            $default = (string) $phpInfo['default'];
        }

        if (count($versions) === 1) {
            /** @var string $only */
            $only = $versions[0];

            return $only;
        }

        rsort($versions, SORT_NATURAL);
        $defaultSelection = $default ?? $versions[0];

        /** @var string $selected */
        $selected = (string) $this->io->promptSelect(
            label: 'PHP version for this deployment:',
            options: $versions,
            default: $defaultSelection
        );

        return $selected;
    }

    /**
     * Check if deployment hooks exist in remote repository.
     *
     * @return list<string> List of missing hook names
     * @throws \RuntimeException If git operations fail
     */
    private function checkRemoteHooksExist(SiteDTO $site): array
    {
        $hookPaths = array_map(
            fn ($hook) => ".deployer/hooks/{$hook}",
            self::REQUIRED_HOOKS
        );

        $remoteHooks = $this->git->checkRemoteFilesExist(
            $site->repo,
            $site->branch,
            $hookPaths
        );

        $missingHooks = [];
        foreach ($remoteHooks as $path => $exists) {
            if (! $exists) {
                $missingHooks[] = basename($path);
            }
        }

        return $missingHooks;
    }
}
