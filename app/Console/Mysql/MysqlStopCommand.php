<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Mysql;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'mysql:stop',
    description: 'Stop MySQL service'
)]
class MysqlStopCommand extends BaseCommand
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

        $this->h1('Stop MySQL Service');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Stop MySQL service
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'mysql-service',
            'Stopping MySQL service...',
            [
                'DEPLOYER_ACTION' => 'stop',
            ],
        );

        if (is_int($result)) {
            $this->nay('Failed to stop MySQL service');

            return Command::FAILURE;
        }

        $this->yay('MySQL service stopped');

        //
        // Show command replay
        // ----

        $this->commandReplay('mysql:stop', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }
}
