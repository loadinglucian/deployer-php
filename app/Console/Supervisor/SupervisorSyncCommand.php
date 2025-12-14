<?php

declare(strict_types=1);

namespace Deployer\Console\Supervisor;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\SupervisorDTO;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
use Deployer\Traits\SupervisorsTrait;
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
    use SupervisorsTrait;

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
        // Sync supervisors to server
        // ----

        $result = $this->executePlaybookSilently(
            $server,
            'supervisor-sync',
            "Syncing supervisor configuration to server...",
            [
                'DEPLOYER_DISTRO' => $distro,
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SITE_DOMAIN' => $site->domain,
                'DEPLOYER_SUPERVISORS' => array_map(
                    fn (SupervisorDTO $supervisor) => [
                        'program' => $supervisor->program,
                        'script' => $supervisor->script,
                        'autostart' => $supervisor->autostart,
                        'autorestart' => $supervisor->autorestart,
                        'stopwaitsecs' => $supervisor->stopwaitsecs,
                        'numprocs' => $supervisor->numprocs,
                    ],
                    $site->supervisors
                ),
            ]
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
            'domain' => $site->domain,
        ]);

        return Command::SUCCESS;
    }
}
