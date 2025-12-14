<?php

declare(strict_types=1);

namespace Deployer\Console\Supervisor;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\SupervisorDTO;
use Deployer\Traits\SitesTrait;
use Deployer\Traits\SupervisorsTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'supervisor:create',
    description: 'Create a supervisor program for a site'
)]
class SupervisorCreateCommand extends BaseCommand
{
    use SitesTrait;
    use SupervisorsTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Site domain')
            ->addOption('program', null, InputOption::VALUE_REQUIRED, 'Process name identifier')
            ->addOption('script', null, InputOption::VALUE_REQUIRED, 'Script in .deployer/supervisors/')
            ->addOption('autostart', null, InputOption::VALUE_NEGATABLE, 'Start on supervisord start')
            ->addOption('autorestart', null, InputOption::VALUE_NEGATABLE, 'Restart on exit')
            ->addOption('stopwaitsecs', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for stop')
            ->addOption('numprocs', null, InputOption::VALUE_REQUIRED, 'Number of process instances');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Create Supervisor Program');

        //
        // Select site
        // ----

        $site = $this->selectSite();

        if (is_int($site)) {
            return $site;
        }

        $this->displaySiteDeets($site);

        //
        // Ensure site has repo/branch configured
        // ----

        if (null === $site->repo || null === $site->branch) {
            $this->warn('Site has not been deployed yet');
            $this->info('Run <fg=cyan>site:deploy</> to deploy the site first');

            return Command::FAILURE;
        }

        //
        // Scan available supervisor scripts from remote repository
        // ----

        try {
            $availableScripts = $this->listRemoteSiteDirectory($site, '.deployer/supervisors');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $availableScripts) {
            $this->warn('No supervisor scripts found in repository');
            $this->info('Run <fg=cyan>scaffold:supervisors</> to create some');

            return Command::FAILURE;
        }

        //
        // Select supervisor script
        // ----

        $this->io->write("\n");

        /** @var string $script */
        $script = (string) $this->io->getOptionOrPrompt(
            'script',
            fn () => $this->io->promptSelect(
                label: 'Select supervisor script:',
                options: $availableScripts,
                scroll: 10
            )
        );

        // Validate CLI option is in available scripts
        if (! in_array($script, $availableScripts, true)) {
            $this->nay("Supervisor script not found: .deployer/supervisors/{$script}");

            return Command::FAILURE;
        }

        // Check for duplicate script in site's supervisors
        $duplicateScriptError = $this->validateSupervisorScript($script, $site->domain);
        if (null !== $duplicateScriptError) {
            $this->nay($duplicateScriptError);

            return Command::FAILURE;
        }

        //
        // Gather program details
        // ----

        $deets = $this->gatherProgramDeets($site->domain);

        if (null === $deets) {
            return Command::FAILURE;
        }

        [
            'program' => $program,
            'autostart' => $autostart,
            'autorestart' => $autorestart,
            'stopwaitsecs' => $stopwaitsecs,
            'numprocs' => $numprocs,
        ] = $deets;

        //
        // Display supervisor details
        // ----

        $supervisor = new SupervisorDTO(
            program: $program,
            script: $script,
            autostart: $autostart,
            autorestart: $autorestart,
            stopwaitsecs: $stopwaitsecs,
            numprocs: $numprocs,
        );

        $this->displaySupervisorDeets($supervisor);

        //
        // Add supervisor to inventory
        // ----

        try {
            $this->sites->addSupervisor($site->domain, $supervisor);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay("Supervisor '{$program}' added to inventory");
        $this->info('Run <fg=cyan>supervisor:sync</> to apply changes to the server');

        //
        // Show command replay
        // ----

        $this->commandReplay('supervisor:create', [
            'domain' => $site->domain,
            'program' => $supervisor->program,
            'script' => $supervisor->script,
            'autostart' => $supervisor->autostart,
            'autorestart' => $supervisor->autorestart,
            'stopwaitsecs' => $supervisor->stopwaitsecs,
            'numprocs' => $supervisor->numprocs,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Gather program details from user input or CLI options.
     *
     * @return array{program: string, autostart: bool, autorestart: bool, stopwaitsecs: int, numprocs: int}|null
     */
    protected function gatherProgramDeets(string $domain): ?array
    {
        /** @var string|null $program */
        $program = $this->io->getValidatedOptionOrPrompt(
            'program',
            fn ($validate) => $this->io->promptText(
                label: 'Program name (unique identifier):',
                placeholder: 'queue-worker',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateProgramInput($value)
        );

        if (null === $program) {
            return null;
        }

        // Check for duplicate program in site's supervisors
        $duplicateError = $this->validateSupervisorProgram($program, $domain);
        if (null !== $duplicateError) {
            $this->nay($duplicateError);

            return null;
        }

        /** @var bool $autostart */
        $autostart = $this->io->getOptionOrPrompt(
            'autostart',
            fn () => $this->io->promptConfirm(
                label: 'Start on supervisord start?',
                default: true
            )
        );

        /** @var bool $autorestart */
        $autorestart = $this->io->getOptionOrPrompt(
            'autorestart',
            fn () => $this->io->promptConfirm(
                label: 'Restart on exit?',
                default: true
            )
        );

        /** @var string|int|null $stopwaitsecs */
        $stopwaitsecs = $this->io->getValidatedOptionOrPrompt(
            'stopwaitsecs',
            fn ($validate) => $this->io->promptText(
                label: 'Seconds to wait for stop:',
                default: '3600',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateStopWaitSecsInput($value)
        );

        if (null === $stopwaitsecs) {
            return null;
        }

        /** @var string|int|null $numprocs */
        $numprocs = $this->io->getValidatedOptionOrPrompt(
            'numprocs',
            fn ($validate) => $this->io->promptText(
                label: 'Number of process instances:',
                default: '1',
                required: true,
                validate: $validate
            ),
            fn ($value) => $this->validateNumprocsInput($value)
        );

        if (null === $numprocs) {
            return null;
        }

        return [
            'program' => $program,
            'autostart' => $autostart,
            'autorestart' => $autorestart,
            'stopwaitsecs' => (int) $stopwaitsecs,
            'numprocs' => (int) $numprocs,
        ];
    }

    // ----
    // Validation
    // ----

    /**
     * Validate supervisor program is not a duplicate for this site.
     *
     * @return string|null Error message if invalid, null if valid
     */
    private function validateSupervisorProgram(string $program, string $domain): ?string
    {
        // Check for duplicate in site's supervisors
        // Note: Programs are namespaced by domain in supervisor config (e.g., example.com-horizon)
        // so we only need to check for duplicates within the same site
        $site = $this->sites->findByDomain($domain);
        if (null !== $site) {
            foreach ($site->supervisors as $existingSupervisor) {
                if ($existingSupervisor->program === $program) {
                    return "Supervisor '{$program}' is already configured for '{$domain}'";
                }
            }
        }

        return null;
    }

    /**
     * Validate supervisor script is not a duplicate for this site.
     *
     * @return string|null Error message if invalid, null if valid
     */
    private function validateSupervisorScript(string $script, string $domain): ?string
    {
        // Check for duplicate in site's supervisors
        $site = $this->sites->findByDomain($domain);
        if (null !== $site) {
            foreach ($site->supervisors as $existingSupervisor) {
                if ($existingSupervisor->script === $script) {
                    return "Supervisor script '{$script}' is already configured for '{$domain}'";
                }
            }
        }

        return null;
    }
}
