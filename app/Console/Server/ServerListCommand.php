<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\ServerHelpersTrait;
use Bigpixelrocket\DeployerPHP\Traits\SiteHelpersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:list', description: 'List servers in the inventory')]
class ServerListCommand extends BaseCommand
{
    use ServerHelpersTrait;
    use SiteHelpersTrait;

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('List Servers');

        //
        // Get all servers

        $allServers = $this->ensureServersAvailable();

        if (is_int($allServers)) {
            return $allServers;
        }

        //
        // Display servers with their sites

        foreach ($allServers as $count => $server) {
            // Display server with sites
            $serverSites = $this->sites->findByServer($server->name);
            $this->displayServerDeets($server, $serverSites);

            if ($count < count($allServers) - 1) {
                $this->io->writeln([
                        '  ───',
                        '',
                    ]);
            }
        }

        return Command::SUCCESS;
    }

}
