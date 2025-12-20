<?php

declare(strict_types=1);

namespace Deployer\Console\Memcached;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'memcached:install',
    description: 'Install Memcached server on a server'
)]
class MemcachedInstallCommand extends BaseCommand
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

        $this->h1('Install Memcached');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Install Memcached
        // ----

        $result = $this->executePlaybook(
            $server,
            'memcached-install',
            'Installing Memcached...',
        );

        if (is_int($result)) {
            return $result;
        }

        //
        // Output result
        // ----

        if ($result['already_installed'] ?? false) {
            $this->info('Memcached is already installed on this server');
        } else {
            $this->yay('Memcached installation completed successfully');
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('memcached:install', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }
}
