<?php

declare(strict_types=1);

namespace Deployer\Console\Site;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\SiteServerDTO;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'site:delete',
    description: 'Delete a site from a server and remove it from inventory'
)]
class SiteDeleteCommand extends BaseCommand
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
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Domain name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip typing the site domain to confirm')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip Yes/No confirmation prompt')
            ->addOption('inventory-only', null, InputOption::VALUE_NONE, 'Only remove from inventory, skip remote site deletion');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Delete Site');

        //
        // Select site
        // ----

        $site = $this->selectSiteDeets();

        if (is_int($site)) {
            return $site;
        }

        //
        // Display deletion info
        // ----

        /** @var bool $inventoryOnly */
        $inventoryOnly = $input->getOption('inventory-only');

        $deletionInfo = [
            'Remove the site from inventory',
        ];

        if (!$inventoryOnly) {
            $deletionInfo[] = "Delete site files from server '{$site->server}'";
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
            $this->out('');

            $typedDomain = $this->io->promptText(
                label: "Type the site domain '{$site->domain}' to confirm deletion:",
                required: true
            );

            if ($typedDomain !== $site->domain) {
                $this->nay('Site domain does not match. Deletion cancelled.');

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
            $this->warn('Cancelled deleting site');

            return Command::SUCCESS;
        }

        //
        // Attempt to delete site from server
        // ----

        $deletedFromServer = false;

        if (!$inventoryOnly) {
            $server = $this->servers->findByName($site->server);

            if ($server === null) {
                $this->warn("Server '{$site->server}' not found in inventory");
            } else {
                $server = $this->getServerInfo($server);

                if (is_int($server) || $server->info === null) {
                    $this->warn('Could not connect to server');
                } else {
                    // Execute site deletion playbook
                    $siteServer = new SiteServerDTO($site, $server);

                    $result = $this->executePlaybookSilently(
                        $siteServer,
                        'site-delete',
                        'Deleting site from server...'
                    );

                    if (is_int($result)) {
                        $this->warn('Failed to delete site from server');
                    } else {
                        $deletedFromServer = true;
                    }
                }
            }

            //
            // Confirm inventory removal if server deletion failed
            // ----

            if (!$deletedFromServer) {
                $proceedAnyway = $this->io->getBooleanOptionOrPrompt(
                    'inventory-only',
                    fn (): bool => $this->io->promptConfirm(
                        label: 'Remove site from inventory anyway?',
                        default: false
                    )
                );

                if (!$proceedAnyway) {
                    return Command::FAILURE;
                }
            }
        }

        //
        // Delete site from inventory
        // ----

        $this->sites->delete($site->domain);

        $this->yay("Site '{$site->domain}' removed from inventory");

        //
        // Show command replay
        // ----

        $replayOptions = [
            'domain' => $site->domain,
            'force' => true,
            'yes' => true,
        ];

        // If we made it this far without deleting from server,
        // add --inventory-only to the command replay
        if (!$deletedFromServer) {
            $replayOptions['inventory-only'] = true;
        }

        $this->commandReplay('site:delete', $replayOptions);

        return Command::SUCCESS;
    }
}
