<?php

declare(strict_types=1);

namespace Deployer\Console\Server;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:list',
    description: 'List servers in the inventory'
)]
class ServerListCommand extends BaseCommand
{
    use ServersTrait;
    use SitesTrait;

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('List Servers');

        //
        // Get all servers
        // ----

        $allServers = $this->ensureServersAvailable();

        if (is_int($allServers)) {
            return $allServers;
        }

        //
        // Display servers
        // ----

        foreach ($allServers as $server) {
            $this->displayServerDeets($server);
        }

        return Command::SUCCESS;
    }

}
