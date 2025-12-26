<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Cron;

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
    name: 'cron:sync',
    description: 'Sync cron jobs to a server'
)]
class CronSyncCommand extends BaseCommand
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

        $this->h1('Sync Cron Jobs');

        //
        // Select site and server
        // ----

        $siteServer = $this->selectSiteDeetsWithServer();

        if (is_int($siteServer)) {
            return $siteServer;
        }

        //
        // Sync crons to server
        // ----

        $result = $this->executePlaybookSilently(
            $siteServer,
            'cron-sync',
            "Syncing cron configuration to server..."
        );

        if (is_int($result)) {
            $this->nay('Failed to sync crons to server');

            return Command::FAILURE;
        }

        $this->yay('Cron configuration synced to server');

        //
        // Show command replay
        // ----

        $this->commandReplay('cron:sync', [
            'domain' => $siteServer->site->domain,
        ]);

        return Command::SUCCESS;
    }
}
