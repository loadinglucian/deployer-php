<?php

declare(strict_types=1);

namespace Deployer\Console\Server;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\DigitalOceanTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:delete',
    description: 'Delete a server from inventory'
)]
class ServerDeleteCommand extends BaseCommand
{
    use DigitalOceanTrait;
    use ServersTrait;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip typing the server name to confirm')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip Yes/No confirmation prompt');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Delete Server');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server)) {
            return $server;
        }

        $serverSites = $this->sites->findByServer($server->name);

        $siteCount = count($serverSites);

        $isDigitalOceanServer = $server->provider === 'digitalocean' && $server->dropletId !== null;

        //
        // Display deletion info
        // ----

        $deletionInfo = [
            'Remove the server from inventory',
        ];

        if ($siteCount > 0) {
            $siteDomains = array_map(fn ($site) => $site->domain, $serverSites);
            $sitesList = implode(', ', $siteDomains);
            $deletionInfo[] = "Delete {$siteCount} associated site(s): {$sitesList}";
        }

        if ($isDigitalOceanServer) {
            $deletionInfo[] = "Destroy the droplet on DigitalOcean (ID: {$server->dropletId})";
        }

        $this->info('This will:');
        $this->ul($deletionInfo);

        //
        // Confirm deletion with extra safety
        // ----

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force') ?? false;

        if (!$forceSkip) {
            $typedName = $this->io->promptText(
                label: "Type the server name '{$server->name}' to confirm deletion:",
                required: true
            );

            if ($typedName !== $server->name) {
                $this->nay('Server name does not match. Deletion cancelled.');

                return Command::FAILURE;
            }
        }

        $confirmed = $this->io->getBooleanOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );

        if (!$confirmed) {
            $this->warn('Cancelled deleting server');

            return Command::SUCCESS;
        }

        //
        // Destroy cloud provider resources
        // ----

        $destroyed = false;

        if ($isDigitalOceanServer && $server->dropletId !== null) {
            try {
                if (Command::FAILURE === $this->initializeDigitalOceanAPI()) {
                    throw new \RuntimeException('Destroying droplet failed');
                }

                $this->io->promptSpin(
                    fn () => $this->digitalOcean->droplet->destroyDroplet($server->dropletId),
                    "Destroying droplet (ID: {$server->dropletId})"
                );

                $this->yay('Droplet destroyed (ID: ' . $server->dropletId . ')');

                $destroyed = true;
            } catch (\RuntimeException $e) {
                $this->nay($e->getMessage());

                $continueAnyway = $this->io->promptConfirm(
                    label: 'Remove from inventory anyway?',
                    default: true
                );

                if (!$continueAnyway) {
                    return Command::FAILURE;
                }
            }
        }

        //
        // Delete server from inventory
        // ----

        $this->servers->delete($server->name);

        $this->yay("Server '{$server->name}' removed from inventory");

        //
        // Delete associated sites
        // ----

        if (count($serverSites) > 0) {
            foreach ($serverSites as $site) {
                $this->sites->delete($site->domain);
            }

            $sitesText = $siteCount === 1 ? 'site' : 'sites';
            $this->yay("Deleted {$siteCount} associated {$sitesText}");
        }

        if (!$destroyed) {
            $this->warn('Your server may still be running and incurring costs');
            $this->out('Check with your cloud provider to ensure it is fully terminated');
        }

        //
        // Show command replay
        // ----

        $this->commandReplay('server:delete', [
            'server' => $server->name,
            'force' => true,
            'yes' => $confirmed,
        ]);

        return Command::SUCCESS;
    }
}
