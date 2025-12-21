<?php

declare(strict_types=1);

namespace Deployer\Console\Memcached;

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
    name: 'memcached:logs',
    description: 'View Memcached service logs'
)]
class MemcachedLogsCommand extends BaseCommand
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

        $this->h1('Memcached Logs');

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
        // Retrieve Memcached service logs
        // ----

        $this->retrieveJournalLogs($server, 'Memcached Service', 'memcached', $lineCount);

        //
        // Retrieve Memcached file logs
        // ----

        $this->retrieveFileLogs($server, 'Memcached Log', '/var/log/memcached.log', $lineCount);

        //
        // Show command replay
        // ----

        $this->commandReplay('memcached:logs', [
            'server' => $server->name,
            'lines' => $lines,
        ]);

        return Command::SUCCESS;
    }
}
