<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\PlaybooksTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:install:php',
    description: 'Install PHP on a server'
)]
class ServerInstallPhpCommand extends BaseCommand
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
        $this->addOption('php-version', null, InputOption::VALUE_REQUIRED, 'PHP version to install');
        $this->addOption('php-default', null, InputOption::VALUE_NONE, 'Set as default PHP version');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->heading('Install PHP');

        //
        // Select server & display details
        // ----

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        $this->displayServerDeets($server);

        //
        // Get server info (verifies SSH connection and validates distribution & permissions)
        // ----

        $info = $this->serverInfo($server);

        if (is_int($info)) {
            return $info;
        }

        //
        // Install PHP
        // ----

        $phpResult = $this->installPhp($server, $info);

        if (is_int($phpResult)) {
            return $phpResult;
        }

        /** @var array{status: int, php_version: string, php_default: bool} $phpResult */
        $phpVersion = $phpResult['php_version'];
        $phpDefault = $phpResult['php_default'];

        //
        // Show command replay
        // ----

        $this->showCommandReplay('server:install:php', [
            'server' => $server->name,
            'php-version' => $phpVersion,
            'php-default' => $phpDefault,
        ]);

        return Command::SUCCESS;
    }

}
