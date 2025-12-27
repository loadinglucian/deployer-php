<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Nginx;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'nginx:start',
    description: 'Start Nginx service'
)]
class NginxStartCommand extends BaseCommand
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

        $this->h1('Start Nginx Service');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Start Nginx service
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'nginx-service',
            'Starting Nginx service...',
            [
                'DEPLOYER_ACTION' => 'start',
            ],
        );

        if (is_int($result)) {
            $this->nay('Failed to start Nginx service');

            return Command::FAILURE;
        }

        $this->yay('Nginx service started');

        //
        // Show command replay
        // ----

        $this->commandReplay('nginx:start', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }
}
