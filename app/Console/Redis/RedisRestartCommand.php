<?php

declare(strict_types=1);

namespace Deployer\Console\Redis;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'redis:restart',
    description: 'Restart Redis service'
)]
class RedisRestartCommand extends BaseCommand
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

        $this->h1('Restart Redis Service');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Restart Redis service
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'redis-service',
            'Restarting Redis service...',
            [
                'DEPLOYER_ACTION' => 'restart',
            ],
        );

        if (is_int($result)) {
            $this->nay('Failed to restart Redis service');

            return Command::FAILURE;
        }

        $this->yay('Redis service restarted');

        //
        // Show command replay
        // ----

        $this->commandReplay('redis:restart', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }
}
