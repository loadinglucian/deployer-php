<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Supervisor;

use DeployerPHP\Builders\SupervisorBuilder;
use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\DTOs\SiteDTO;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\SitesTrait;
use DeployerPHP\Traits\SupervisorsTrait;
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
    use PlaybooksTrait;
    use ServersTrait;
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
            ->addOption('script', null, InputOption::VALUE_REQUIRED, 'Script path relative to project directory')
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

        $site = $this->selectSiteDeets();

        if (is_int($site)) {
            return $site;
        }

        $deployedResult = $this->ensureSiteDeployed($site);

        if (is_int($deployedResult)) {
            return $deployedResult;
        }

        //
        // Gather supervisor details
        // ----

        $this->io->write("\n");

        $deets = $this->gatherSupervisorDeets($site);

        if (is_int($deets)) {
            return $deets;
        }

        $supervisor = SupervisorBuilder::new()
            ->program($deets['program'])
            ->script($deets['script'])
            ->autostart($deets['autostart'])
            ->autorestart($deets['autorestart'])
            ->stopwaitsecs($deets['stopwaitsecs'])
            ->numprocs($deets['numprocs'])
            ->build();

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

        $this->yay("Supervisor '{$supervisor->program}' added to inventory");
        $this->info('Run <fg=cyan>supervisor:sync</> to apply changes to the server');

        //
        // Show command replay
        // ----

        $this->commandReplay([
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
     * Gather supervisor details from user input or CLI options.
     *
     * @return array{script: string, program: string, autostart: bool, autorestart: bool, stopwaitsecs: int, numprocs: int}|int
     */
    protected function gatherSupervisorDeets(SiteDTO $site): array|int
    {
        try {
            /** @var string $script */
            $script = $this->io->getValidatedOptionOrPrompt(
                'script',
                fn ($validate) => $this->io->promptText(
                    label: 'Supervisor script path (relative to project root):',
                    placeholder: '.deployer/scripts/supervisor.sh',
                    required: true,
                    validate: $validate
                ),
                fn ($value) => $this->validateSupervisorScriptInput($value)
            );
            $script = $this->normalizeScriptPath($script);

            // Check for duplicate script in site's supervisors
            $duplicateScriptError = $this->validateSupervisorScript($script, $site->domain);
            if (null !== $duplicateScriptError) {
                $this->nay($duplicateScriptError);

                return Command::FAILURE;
            }

            try {
                $scriptExists = $this->scriptExistsInRemoteRepo($script, $site);
            } catch (\RuntimeException $e) {
                $this->nay($e->getMessage());

                return Command::FAILURE;
            }

            if (! $scriptExists) {
                $this->nay("Supervisor script not found in repository: {$script}");

                return Command::FAILURE;
            }

            /** @var string $program */
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
            $program = trim($program);

            // Check for duplicate program in site's supervisors
            $duplicateProgramError = $this->validateSupervisorProgram($program, $site->domain);
            if (null !== $duplicateProgramError) {
                $this->nay($duplicateProgramError);

                return Command::FAILURE;
            }

            $autostart = $this->io->getBooleanOptionOrPrompt(
                'autostart',
                fn () => $this->io->promptConfirm(
                    label: 'Start on supervisord start?',
                    default: true
                )
            );

            $autorestart = $this->io->getBooleanOptionOrPrompt(
                'autorestart',
                fn () => $this->io->promptConfirm(
                    label: 'Restart on exit?',
                    default: true
                )
            );

            /** @var string|int $stopwaitsecs */
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

            /** @var string|int $numprocs */
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
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        return [
            'script' => $script,
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
                if ($this->normalizeScriptPath($existingSupervisor->script) === $script) {
                    return "Supervisor script '{$script}' is already configured for '{$domain}'";
                }
            }
        }

        return null;
    }

}
