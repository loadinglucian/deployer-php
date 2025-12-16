<?php

declare(strict_types=1);

namespace Deployer\Console\Mariadb;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\LogsTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mariadb:status',
    description: 'View MariaDB service status'
)]
class MariadbStatusCommand extends BaseCommand
{
    use LogsTrait;
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

        $this->h1('MariaDB Service Status');

        //
        // Select server
        // ----

        $server = $this->selectServer();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Get number of lines
        // ----

        /** @var ?string $lines */
        $lines = $this->io->getValidatedOptionOrPrompt(
            'lines',
            fn ($validate) => $this->io->promptText(
                label: 'Number of lines:',
                default: '50',
                validate: $validate
            ),
            fn ($value) => $this->validateLineCount($value)
        );

        if (null === $lines) {
            return Command::FAILURE;
        }

        $lineCount = (int) $lines;

        //
        // Retrieve MariaDB logs via journalctl
        // ----

        try {
            $command = sprintf('journalctl -u mariadb -n %d --no-pager 2>&1', $lineCount);
            $result = $this->ssh->executeCommand($server, $command);
            $logOutput = trim($result['output']);

            $noData = '' === $logOutput ||
                      '-- No entries --' === $logOutput ||
                      str_contains($logOutput, 'No data available');

            if (0 !== $result['exit_code'] && !$noData) {
                $this->nay('Failed to retrieve MariaDB logs');
                $this->io->write($this->highlightErrors($logOutput), true);
                $this->out('---');

                return Command::FAILURE;
            }

            if ($noData) {
                $this->warn('No MariaDB logs found. MariaDB may not be installed or has no recent activity.');
            } else {
                $this->io->write($this->highlightErrors($logOutput), true);
            }

            $this->out('---');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('mariadb:status', [
            'server' => $server->name,
            'lines' => $lines,
        ]);

        return Command::SUCCESS;
    }
}
