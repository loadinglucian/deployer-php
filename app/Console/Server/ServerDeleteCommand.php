<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Server;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Traits\AwsTrait;
use DeployerPHP\Traits\DoTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
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
    use AwsTrait;
    use DoTrait;
    use PlaybooksTrait;
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
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip Yes/No confirmation prompt')
            ->addOption('inventory-only', null, InputOption::VALUE_NONE, 'Only remove from inventory, skip cloud provider destruction');
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

        //
        // Display deletion info
        // ----

        /** @var bool $inventoryOnly */
        $inventoryOnly = $input->getOption('inventory-only');

        $deletionInfo = [
            'Remove the server from inventory',
        ];

        if ($siteCount > 0) {
            $siteDomains = array_map(fn ($site) => $site->domain, $serverSites);
            $sitesList = implode(', ', $siteDomains);
            $deletionInfo[] = "Delete {$siteCount} associated site(s): {$sitesList}";
        }

        if ($server->isDo() && !$inventoryOnly) {
            $deletionInfo[] = "Destroy the droplet on DigitalOcean (ID: {$server->dropletId})";
        }

        if ($server->isAws() && !$inventoryOnly) {
            $deletionInfo[] = "Terminate the EC2 instance on AWS (ID: {$server->instanceId})";
            $deletionInfo[] = 'Release associated Elastic IP (if any)';
        }

        if (1 === count($deletionInfo)) {
            $this->info('This will ' . lcfirst($deletionInfo[0]));
        } else {
            $this->info('This will:');
            $this->ul($deletionInfo);
        }

        //
        // Confirm deletion with extra safety
        // ----

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force');

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

        if ($server->isDo() && !$inventoryOnly) {
            try {
                if (Command::FAILURE === $this->initializeDoAPI()) {
                    throw new \RuntimeException('Destroying droplet failed');
                }

                /** @var int $dropletId */
                $dropletId = $server->dropletId;

                $this->io->promptSpin(
                    fn () => $this->do->droplet->destroyDroplet($dropletId),
                    "Destroying droplet (ID: {$dropletId})"
                );

                $this->yay('Droplet destroyed (ID: ' . $dropletId . ')');

                $destroyed = true;
            } catch (\RuntimeException $e) {
                $this->nay($e->getMessage());

                $continueAnyway = $this->io->getBooleanOptionOrPrompt(
                    'inventory-only',
                    fn (): bool => $this->io->promptConfirm(
                        label: 'Remove from inventory anyway?',
                        default: true
                    )
                );

                if (!$continueAnyway) {
                    return Command::FAILURE;
                }
            }
        } elseif ($server->isAws() && !$inventoryOnly) {
            try {
                if (Command::FAILURE === $this->initializeAwsAPI()) {
                    throw new \RuntimeException('Terminating instance failed');
                }

                /** @var string $instanceId */
                $instanceId = $server->instanceId;

                //
                // Look up Elastic IP before termination (association is lost after termination)

                $elasticIpAllocationId = $this->io->promptSpin(
                    fn () => $this->aws->instance->findElasticIpByInstanceId($instanceId),
                    'Looking up Elastic IP...'
                );

                //
                // Terminate instance

                $this->io->promptSpin(
                    fn () => $this->aws->instance->terminateInstance($instanceId),
                    "Terminating instance (ID: {$instanceId})"
                );

                $this->yay('Instance terminated (ID: ' . $instanceId . ')');

                //
                // Release Elastic IP (if found)

                if (null !== $elasticIpAllocationId) {
                    $this->io->promptSpin(
                        fn () => $this->aws->instance->releaseElasticIp($elasticIpAllocationId),
                        'Releasing Elastic IP...'
                    );

                    $this->yay('Elastic IP released (ID: ' . $elasticIpAllocationId . ')');
                }

                $destroyed = true;
            } catch (\RuntimeException $e) {
                $this->nay($e->getMessage());

                $continueAnyway = $this->io->getBooleanOptionOrPrompt(
                    'inventory-only',
                    fn (): bool => $this->io->promptConfirm(
                        label: 'Remove from inventory anyway?',
                        default: true
                    )
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

        // Either we failed to destroy the server or the user provisioned their server manually somewhere else
        if (!$destroyed) {
            $this->warn('Your server may still be running and incurring costs');
            $this->out('Check with your cloud provider to ensure it is fully terminated');
        }

        //
        // Show command replay
        // ----

        $replayOptions = [
            'server' => $server->name,
            'force' => true,
            'yes' => true,
        ];

        // If we made it this far with a provisioned server that wasn't destroyed,
        // we should add the --inventory-only option to the command replay
        if ($server->isProvisioned() && !$destroyed) {
            $replayOptions['inventory-only'] = true;
        }

        $this->commandReplay('server:delete', $replayOptions);

        return Command::SUCCESS;
    }
}
