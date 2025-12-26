<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Mysql;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\LogsTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mysql:logs',
    description: 'View MySQL service logs'
)]
class MysqlLogsCommand extends BaseCommand
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

        // No default value: omitting --lines displays a prompt with its own default
        $this->addOption('lines', 'n', InputOption::VALUE_REQUIRED, 'Number of lines to retrieve');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('MySQL Logs');

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
        // Retrieve MySQL service logs
        // ----

        $this->retrieveJournalLogs($server, 'MySQL Service', 'mysql', $lineCount);

        //
        // Retrieve MySQL error logs
        // ----

        $this->retrieveFileLogs($server, 'MySQL Error Log', '/var/log/mysql/error.log', $lineCount);

        //
        // Show command replay
        // ----

        $this->commandReplay('mysql:logs', [
            'server' => $server->name,
            'lines' => $lines,
        ]);

        return Command::SUCCESS;
    }
}
