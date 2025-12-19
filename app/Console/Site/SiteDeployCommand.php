<?php

declare(strict_types=1);

namespace Deployer\Console\Site;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\SiteDTO;
use Deployer\DTOs\SiteServerDTO;
use Deployer\Exceptions\ValidationException;
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
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'Git repository URL')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Git branch name')
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
        // Select site and server
        // ----

        $siteServer = $this->selectSiteDeetsWithServer();

        if (is_int($siteServer)) {
            return $siteServer;
        }

        $site = $siteServer->site;
        $server = $siteServer->server;

        //
        // Gather site deets
        // ----

        $resolvedGit = $this->gatherSiteDeets($input, $site);

        if (is_int($resolvedGit)) {
            return Command::FAILURE;
        }

        [$repo, $branch, $needsUpdate] = $resolvedGit;

        // Create updated site DTO with resolved repo/branch
        $site = new SiteDTO(
            domain: $site->domain,
            repo: $repo,
            branch: $branch,
            server: $site->server,
            phpVersion: $site->phpVersion,
            crons: $site->crons,
            supervisors: $site->supervisors,
        );

        // Update siteServer with the resolved site
        $siteServer = new SiteServerDTO($site, $server);

        if ($needsUpdate) {
            try {
                $this->sites->update($site);
                $this->yay('Repository info added to inventory');
            } catch (\RuntimeException $e) {
                $this->warn('Could not update inventory: ' . $e->getMessage());
            }
        }

        //
        // Check for deployment hooks in remote repository
        // ----

        $availableHooks = $this->getAvailableScripts($site, '.deployer/hooks', 'hook', 'scaffold:hooks');

        if (is_int($availableHooks)) {
            return $availableHooks;
        }

        $missingHooks = array_diff(self::REQUIRED_HOOKS, $availableHooks);

        if ([] !== $missingHooks) {
            $this->warn('Missing required deployment hooks:');
            foreach ($missingHooks as $hook) {
                $this->out('  • ' . $hook);
            }
            $this->info("Run <|cyan>scaffold:hooks</> to create them");

            return Command::FAILURE;
        }

        //
        // Validate site is added on server
        // ----

        $validationResult = $this->ensureSiteExists($server, $site);

        if (is_int($validationResult)) {
            return $validationResult;
        }

        //
        // Resolve deployment parameters
        // ----

        $keepReleases = $this->resolveKeepReleases($input);

        if (null === $keepReleases) {
            return Command::FAILURE;
        }

        //
        // Confirm deployment
        // ----

        $confirmed = $this->io->getBooleanOptionOrPrompt(
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

        $result = $this->executePlaybook(
            $siteServer,
            'site-deploy',
            'Deploying site...',
            [
                'DEPLOYER_KEEP_RELEASES' => (string) $keepReleases,
            ]
        );

        if (is_int($result)) {
            return $result;
        }

        $this->yay('Deployment completed');

        $this->displayDeploymentDeets($result, $branch);

        $this->ul([
            'Run <|cyan>site:shared:push</> to upload shared files (e.g. .env)',
            'View server and site logs with <|cyan>server:logs</>',
        ]);

        //
        // Show command replay
        // ----

        $this->commandReplay('site:deploy', [
            'domain' => $site->domain,
            'repo' => $repo,
            'branch' => $branch,
            'keep-releases' => $keepReleases,
            'yes' => true,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Resolve repo and branch from site, CLI options, or prompts.
     *
     * @return array{0: string, 1: string, 2: bool}|int [repo, branch, needsUpdate] or Command::FAILURE on failure
     */
    private function gatherSiteDeets(InputInterface $input, SiteDTO $site): array|int
    {
        $storedRepo = $site->repo;
        $storedBranch = $site->branch;
        $needsUpdate = false;

        try {
            // Resolve repo
            if (null !== $storedRepo && '' !== $storedRepo) {
                // Use stored value, but allow CLI override (with validation)
                /** @var string|null $cliRepo */
                $cliRepo = $input->getOption('repo');

                if (null !== $cliRepo && '' !== $cliRepo) {
                    $error = $this->validateSiteRepo($cliRepo);
                    if (null !== $error) {
                        throw new ValidationException($error);
                    }
                    $repo = $cliRepo;
                    $needsUpdate = true;
                } else {
                    $repo = $storedRepo;
                }
            } else {
                // Not stored - prompt for it
                $defaultRepo = $this->git->detectRemoteUrl() ?? '';

                /** @var string $repo */
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

                $needsUpdate = true;
            }

            // Resolve branch
            if (null !== $storedBranch && '' !== $storedBranch) {
                // Use stored value, but allow CLI override (with validation)
                /** @var string|null $cliBranch */
                $cliBranch = $input->getOption('branch');

                if (null !== $cliBranch && '' !== $cliBranch) {
                    $error = $this->validateSiteBranch($cliBranch);
                    if (null !== $error) {
                        throw new ValidationException($error);
                    }
                    $branch = $cliBranch;
                    $needsUpdate = true;
                } else {
                    $branch = $storedBranch;
                }
            } else {
                // Not stored - prompt for it
                $defaultBranch = $this->git->detectCurrentBranch() ?? 'main';

                /** @var string $branch */
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

                $needsUpdate = true;
            }
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        return [$repo, $branch, $needsUpdate];
    }

    /**
     * Display deployment summary details.
     *
     * @param array<string, mixed> $result
     */
    private function displayDeploymentDeets(array $result, string $branch): void
    {
        $lines = [
            'Branch' => $branch,
        ];

        if (isset($result['release_name']) && is_string($result['release_name'])) {
            $lines['Release'] = $result['release_name'];
        }

        if (isset($result['release_path']) && is_string($result['release_path'])) {
            $lines['Path'] = $result['release_path'];
        }

        if (isset($result['current_path']) && is_string($result['current_path'])) {
            $lines['Current'] = $result['current_path'];
        }

        $this->displayDeets($lines);
        $this->out('───');
    }

    private function resolveKeepReleases(InputInterface $input): ?int
    {
        /** @var string|null $value */
        $value = $input->getOption('keep-releases');
        if (null === $value || '' === trim($value)) {
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
}
