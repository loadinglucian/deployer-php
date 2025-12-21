<?php

declare(strict_types=1);

namespace Deployer\Console\Caddy;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'caddy:stop',
    description: 'Stop Caddy service'
)]
class CaddyStopCommand extends BaseCommand
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

        $this->h1('Stop Caddy Service');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Stop Caddy service
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'caddy-service',
            'Stopping Caddy service...',
            [
                'DEPLOYER_ACTION' => 'stop',
            ],
        );

        if (is_int($result)) {
            $this->nay('Failed to stop Caddy service');

            return Command::FAILURE;
        }

        $this->yay('Caddy service stopped');

        //
        // Show command replay
        // ----

        $this->commandReplay('caddy:stop', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }
}
