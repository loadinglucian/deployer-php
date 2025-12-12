<?php

declare(strict_types=1);

namespace Deployer\Console\Cron;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\CronDTO;
use Deployer\Traits\CronsTrait;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
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
    use CronsTrait;
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
        // Select site
        // ----

        $site = $this->selectSite();

        if (is_int($site)) {
            return $site;
        }

        $this->displaySiteDeets($site);

        //
        // Get server for site
        // ----

        $server = $this->getServerForSite($site);

        if (is_int($server)) {
            return $server;
        }

        //
        // Get server info (verifies SSH and validates distro & permissions)
        // ----

        $server = $this->serverInfo($server);

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        [
            'distro' => $distro,
            'permissions' => $permissions,
        ] = $server->info;

        /** @var string $distro */
        /** @var string $permissions */

        //
        // Get PHP version for site
        // ----

        $siteConfig = $this->getSiteConfig($server->info, $site->domain);
        $phpVersion = $siteConfig['php_version'] ?? null;

        if (null === $phpVersion || 'unknown' === $phpVersion) {
            $this->nay("Could not determine PHP version for site '{$site->domain}'");

            return Command::FAILURE;
        }

        //
        // Sync crons to server
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'site-cron-sync',
            "Syncing cron configuration to server...",
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SITE_DOMAIN' => $site->domain,
                'DEPLOYER_PHP_VERSION' => $phpVersion,
                'DEPLOYER_CRONS' => array_map(
                    fn (CronDTO $cron) => ['script' => $cron->script, 'schedule' => $cron->schedule],
                    $site->crons
                ),
            ]
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
            'domain' => $site->domain,
        ]);

        return Command::SUCCESS;
    }
}
