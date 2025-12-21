<?php

declare(strict_types=1);

namespace Deployer\Console\Supervisor;

use Deployer\Contracts\BaseCommand;
use Deployer\Exceptions\ValidationException;
use Deployer\Traits\LogsTrait;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'supervisor:logs',
    description: 'View supervisor service and program logs'
)]
class SupervisorLogsCommand extends BaseCommand
{
    use LogsTrait;
    use PlaybooksTrait;
    use ServersTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('lines', 'n', InputOption::VALUE_REQUIRED, 'Number of lines to retrieve', '50');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Supervisor Logs');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Get number of lines
        // ----

        try {
            /** @var string $lines */
            $lines = $this->io->getValidatedOptionOrPrompt(
                'lines',
                fn ($validate) => $this->io->promptText(
                    label: 'Number of lines:',
                    default: '50',
                    validate: $validate
                ),
                fn ($value) => $this->validateLineCount($value)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        $lineCount = (int) $lines;

        //
        // Retrieve supervisor service logs
        // ----

        $this->retrieveJournalLogs($server, 'Supervisor', 'supervisor', $lineCount);

        //
        // Retrieve per-site program logs
        // ----

        foreach ($this->sites->findByServer($server->name) as $site) {
            foreach ($site->supervisors as $supervisor) {
                $this->retrieveFileLogs(
                    $server,
                    "Supervisor: {$site->domain}/{$supervisor->program}",
                    "/var/log/supervisor/{$site->domain}-{$supervisor->program}.log",
                    $lineCount
                );
            }
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('supervisor:logs', [
            'server' => $server->name,
            'lines' => $lines,
        ]);

        return Command::SUCCESS;
    }
}
