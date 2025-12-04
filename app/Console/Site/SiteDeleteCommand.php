<?php

declare(strict_types=1);

namespace Deployer\Console\Site;

use Deployer\Contracts\BaseCommand;
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
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip Yes/No confirmation prompt');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Delete Site');

        //
        // Select site & display details
        // ----

        $site = $this->selectSite();

        if (is_int($site)) {
            return $site;
        }

        $this->displaySiteDeets($site);

        //
        // Confirm deletion with extra safety
        // ----

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force') ?? false;

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

        /** @var bool $confirmed */
        $confirmed = $this->io->getOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );

        if (!$confirmed) {
            $this->warn('Cancelled deleting site');
            $this->out('');

            return Command::SUCCESS;
        }

        //
        // Attempt to delete site from server
        // ----

        $deletedFromServer = false;
        $server = $this->servers->findByName($site->server);

        if ($server === null) {
            $this->warn("Server '{$site->server}' not found in inventory");
            $this->out([
                '',
                '<fg=yellow>The server may have been deleted.</>',
                '',
            ]);
        } else {
            // Get server info (verifies SSH connection)
            $server = $this->serverInfo($server);

            if (is_int($server) || $server->info === null) {
                $this->warn('Could not connect to server');
            } else {
                [
                    'distro' => $distro,
                    'permissions' => $permissions,
                ] = $server->info;

                /** @var string $distro */
                /** @var string $permissions */

                // Execute site deletion playbook
                $result = $this->executePlaybookSilently(
                    $server,
                    'site-delete',
                    'Deleting site from server...',
                    [
                        'DEPLOYER_DISTRO' => $distro,
                        'DEPLOYER_PERMS' => $permissions,
                        'DEPLOYER_SITE_DOMAIN' => $site->domain,
                    ]
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
            /** @var bool $proceedAnyway */
            $proceedAnyway = $this->io->getOptionOrPrompt(
                'yes',
                fn (): bool => $this->io->promptConfirm(
                    label: 'Remove site from inventory anyway?',
                    default: false
                )
            );

            if (!$proceedAnyway) {
                return Command::FAILURE;
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

        $this->commandReplay('site:delete', [
            'domain' => $site->domain,
            'force' => true,
            'yes' => $confirmed,
        ]);

        return Command::SUCCESS;
    }
}
