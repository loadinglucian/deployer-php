<?php

declare(strict_types=1);

namespace Deployer\Console\Supervisor;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
use Deployer\Traits\SupervisorsTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'supervisor:delete',
    description: 'Delete a supervisor program from a site'
)]
class SupervisorDeleteCommand extends BaseCommand
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
            ->addOption('program', null, InputOption::VALUE_REQUIRED, 'Supervisor program name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip typing the program name to confirm')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip Yes/No confirmation prompt');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Delete Supervisor Program');

        //
        // Select site and supervisor
        // ----

        $site = $this->selectSiteDeets();

        if (is_int($site)) {
            return $site;
        }

        $this->io->write("\n");

        $supervisor = $this->selectSupervisor($site);

        if (is_int($supervisor)) {
            return $supervisor;
        }

        $this->displaySupervisorDeets($supervisor);

        //
        // Confirm deletion with extra safety
        // ----

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force');

        if (!$forceSkip) {
            $this->io->write("\n");

            $typedProgram = $this->io->promptText(
                label: "Type the program name '{$supervisor->program}' to confirm deletion:",
                required: true
            );

            if ($typedProgram !== $supervisor->program) {
                $this->nay('Program name does not match. Deletion cancelled.');

                return Command::FAILURE;
            }
        }

        $confirmed = $this->io->getBooleanOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );

        if (!$confirmed) {
            $this->warn('Cancelled deleting supervisor program');

            return Command::SUCCESS;
        }

        //
        // Delete supervisor from inventory
        // ----

        try {
            $this->sites->deleteSupervisor($site->domain, $supervisor->program);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $this->yay("Supervisor '{$supervisor->program}' removed from inventory");
        $this->info('Run <fg=cyan>supervisor:sync</> to apply changes to the server');

        //
        // Show command replay
        // ----

        $this->commandReplay('supervisor:delete', [
            'domain' => $site->domain,
            'program' => $supervisor->program,
            'force' => true,
            'yes' => true,
        ]);

        return Command::SUCCESS;
    }
}
