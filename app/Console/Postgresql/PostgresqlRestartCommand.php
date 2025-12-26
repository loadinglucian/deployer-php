<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Postgresql;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'postgresql:restart',
    description: 'Restart PostgreSQL service'
)]
class PostgresqlRestartCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Restart PostgreSQL Service');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Restart PostgreSQL service
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'postgresql-service',
            'Restarting PostgreSQL service...',
            [
                'DEPLOYER_ACTION' => 'restart',
            ],
        );

        if (is_int($result)) {
            $this->nay('Failed to restart PostgreSQL service');

            return Command::FAILURE;
        }

        $this->yay('PostgreSQL service restarted');

        //
        // Show command replay
        // ----

        $this->commandReplay('postgresql:restart', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }
}
