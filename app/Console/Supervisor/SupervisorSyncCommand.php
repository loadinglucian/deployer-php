<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Supervisor;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'supervisor:sync',
    description: 'Sync supervisor programs to a server'
)]
class SupervisorSyncCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Site domain');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Sync Supervisor Programs');

        //
        // Select site and server
        // ----

        $siteServer = $this->selectSiteDeetsWithServer();

        if (is_int($siteServer)) {
            return $siteServer;
        }

        //
        // Sync supervisors to server
        // ----

        $result = $this->executePlaybookSilently(
            $siteServer,
            'supervisor-sync',
            "Syncing supervisor configuration to server..."
        );

        if (is_int($result)) {
            $this->nay('Failed to sync supervisors to server');

            return Command::FAILURE;
        }

        $this->yay('Supervisor configuration synced to server');

        //
        // Show command replay
        // ----

        $this->commandReplay('supervisor:sync', [
            'domain' => $siteServer->site->domain,
        ]);

        return Command::SUCCESS;
    }
}
