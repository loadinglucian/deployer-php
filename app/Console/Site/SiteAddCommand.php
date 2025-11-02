<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Site;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\DTOs\SiteDTO;
use Bigpixelrocket\DeployerPHP\Traits\ServerHelpersTrait;
use Bigpixelrocket\DeployerPHP\Traits\SiteHelpersTrait;
use Bigpixelrocket\DeployerPHP\Traits\SiteValidationTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Add and register a new site to the inventory.
 *
 * Prompts for site details and saves to inventory.
 */
#[AsCommand(name: 'site:add', description: 'Add a new site to the inventory')]
class SiteAddCommand extends BaseCommand
{
    use ServerHelpersTrait;
    use SiteHelpersTrait;
    use SiteValidationTrait;

    //
    // Configuration
    // -------------------------------------------------------------------------------

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain name')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Site source: git or local')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'Git repository URL (for git sites)')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Git branch name (for git sites)')
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
    }

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('Add New Site');

        //
        // Select server

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        //
        // Gather site details

        /** @var string|null $domain */
        $domain = $this->io->getValidatedOptionOrPrompt(
            'domain',
            fn ($validate) => $this->io->promptText(
                label: 'Domain name:',
                placeholder: 'example.com',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateDomainInput($value)
        );

        if ($domain === null) {
            return Command::FAILURE;
        }

        //
        // Select site source

        /** @var string $siteSource */
        $siteSource = $this->io->getOptionOrPrompt(
            'source',
            fn (): string => (string) $this->io->promptSelect(
                label: 'Deploy from:',
                options: ['git' => 'Git Repository', 'local' => 'Local files'],
                default: 'git'
            )
        );

        $isLocal = $siteSource === 'local';

        //
        // Gather git-specific details

        $repo = null;
        $branch = null;

        if (!$isLocal) {
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
                fn ($value) => $this->validateRepoInput($value)
            );

            if ($repo === null) {
                return Command::FAILURE;
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
                fn ($value) => $this->validateBranchInput($value)
            );

            if ($branch === null) {
                return Command::FAILURE;
            }
        }

        //
        // Create DTO and display site info

        $site = new SiteDTO(
            domain: $domain,
            repo: $repo,
            branch: $branch,
            servers: [$server->name]
        );

        $this->io->hr();

        $this->displaySiteDeets($site);
        $this->io->writeln('');

        //
        // Save to repository

        try {
            $this->sites->create($site);
        } catch (\RuntimeException $e) {
            $this->io->error('Failed to add site: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->io->success('Site added successfully');
        $this->io->writeln('');

        //
        // Show command hint

        $hintOptions = [
            'domain' => $domain,
            'source' => $siteSource,
            'server' => $server->name,
        ];

        if (!$isLocal) {
            $hintOptions['repo'] = $repo;
            $hintOptions['branch'] = $branch;
        }

        $this->io->showCommandHint('site:add', $hintOptions);

        return Command::SUCCESS;
    }
}
