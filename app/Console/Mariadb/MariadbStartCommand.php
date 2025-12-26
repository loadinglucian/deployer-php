<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Mariadb;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mariadb:start',
    description: 'Start MariaDB service'
)]
class MariadbStartCommand extends BaseCommand
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

        $this->h1('Start MariaDB Service');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Start MariaDB service
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'mariadb-service',
            'Starting MariaDB service...',
            [
                'DEPLOYER_ACTION' => 'start',
            ],
        );

        if (is_int($result)) {
            $this->nay('Failed to start MariaDB service');

            return Command::FAILURE;
        }

        $this->yay('MariaDB service started');

        //
        // Show command replay
        // ----

        $this->commandReplay('mariadb:start', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }
}
