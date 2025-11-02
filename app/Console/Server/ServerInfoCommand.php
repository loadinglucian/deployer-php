<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\ServerHelpersTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServerInfoTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:info', description: 'Display server information')]
class ServerInfoCommand extends BaseCommand
{
    use ServerHelpersTrait;
    use ServerInfoTrait;

    //
    // Configuration
    // -------------------------------------------------------------------------------

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
    }

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('Server Information');

        //
        // Select server

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        // Get sites for this server
        $serverSites = $this->sites->findByServer($server->name);

        //
        // Display server details

        $this->io->hr();

        $this->displayServerDeets($server, $serverSites);

        //
        // Display server information

        $info = $this->getServerInfo($server);

        if (is_int($info)) {
            return $info;
        }

        $this->displayServerInfo($info);

        //
        // Show command hint

        $this->io->showCommandHint('server:info', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }

}
