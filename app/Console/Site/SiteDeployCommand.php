<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Site;

use DeployerPHP\Builders\SiteBuilder;
use DeployerPHP\Builders\SiteServerBuilder;
use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\DTOs\SiteDTO;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:deploy',
    description: 'Deploy a site by running the deployment playbook and scripts'
)]
class SiteDeployCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;

    private const DEFAULT_KEEP_RELEASES = 5;

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
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip typing the site domain to confirm')
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
        $site = SiteBuilder::from($site)
            ->repo($repo)
            ->branch($branch)
            ->build();

        // Update siteServer with the resolved site
        $siteServer = SiteServerBuilder::new()
            ->site($site)
            ->server($server)
            ->build();

        if ($needsUpdate) {
            try {
                $this->sites->update($site);
                $this->yay('Repository info added to inventory');
            } catch (\RuntimeException $e) {
                $this->warn('Could not update inventory: ' . $e->getMessage());
            }
        }

        //
        // Check for deployment scripts in remote repository
        // ----

        try {
            $availableScripts = $this->getAvailableScripts($site, '.deployer/scripts');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $expectedScripts = $this->getExpectedScripts();
        $scriptsStatus = $this->getScriptsStatus($availableScripts, $expectedScripts);
        $missingScripts = array_keys(array_filter($scriptsStatus, fn ($s) => 'missing' === $s));
        $hasMissingScripts = [] !== $missingScripts;

        $this->out('Deployment scripts:');
        $this->displayDeets($scriptsStatus);
        $this->out('───');

        if ($hasMissingScripts) {
            $this->warn('Missing scripts will be skipped.');
            $this->info('Run <|cyan>scaffold:scripts</> to create them.');
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
        // Confirm deployment with type-to-confirm
        // ----

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force');

        if (! $forceSkip) {
            $typedDomain = $this->io->promptText(
                label: "Type the site domain '{$site->domain}' to confirm deployment:",
                required: true
            );

            if ($typedDomain !== $site->domain) {
                $this->nay('Site domain does not match. Deployment cancelled.');

                return Command::FAILURE;
            }
        }

        $confirmed = $this->io->getBooleanOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Deploy now?',
                default: true
            )
        );

        if (! $confirmed) {
            $this->warn('Deployment cancelled.');

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

        $this->commandReplay([
            'domain' => $site->domain,
            'repo' => $repo,
            'branch' => $branch,
            'keep-releases' => $keepReleases,
            'force' => true,
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

    /**
     * Get expected deployment scripts.
     *
     * @return array<int, string>
     */
    private function getExpectedScripts(): array
    {
        return ['deploy.sh'];
    }

    /**
     * Build status array for scripts (present/missing).
     *
     * @param array<int, string> $availableScripts Scripts found in repository
     * @param array<int, string> $expectedScripts  Scripts from scaffolds directory
     * @return array<string, string> Script name => status
     */
    private function getScriptsStatus(array $availableScripts, array $expectedScripts): array
    {
        $status = [];

        foreach ($expectedScripts as $script) {
            $status[$script] = in_array($script, $availableScripts, true) ? 'present' : 'missing';
        }

        return $status;
    }
}
