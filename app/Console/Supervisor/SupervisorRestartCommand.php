<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Supervisor;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'supervisor:restart',
    description: 'Restart supervisord service'
)]
class SupervisorRestartCommand extends BaseCommand
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

        $this->h1('Restart Supervisord Service');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Restart supervisord service
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'supervisor-service',
            'Restarting supervisord service...',
            [
                'DEPLOYER_ACTION' => 'restart',
            ],
        );

        if (is_int($result)) {
            $this->nay('Failed to restart supervisord service');

            return Command::FAILURE;
        }

        $this->yay('Supervisord service restarted');

        //
        // Show command replay
        // ----

        $this->commandReplay('supervisor:restart', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }
}
