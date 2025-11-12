<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\DigitalOceanTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:delete',
    description: 'Delete a server from the inventory'
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

        $this->heading('Delete Server');

        //
        // Select server & display details
        // ----

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        $this->displayServerDeets($server);

        //
        // Check if server has sites
        // ----

        $serverSites = $this->sites->findByServer($server->name);

        if (count($serverSites) > 0) {
            $this->io->warning("Cannot delete server '{$server->name}' because it has one or more sites.");
            $this->io->writeln([
                '',
                'Use <fg=cyan>site:delete</> to delete the sites first.',
                '',
            ]);

            return Command::FAILURE;
        }

        //
        // Initialize provider API
        // ----

        $isDigitalOceanServer = $this->isDigitalOceanServer($server);
        if ($isDigitalOceanServer && Command::FAILURE === $this->initializeDigitalOceanAPI()) {
            $this->nay('Cannot delete server: DigitalOcean API authentication failed.');
            $this->io->writeln([
                '',
                'You must authenticate with DigitalOcean to delete provisioned servers.',
                'The server will not be removed from inventory to prevent orphaned cloud resources.',
                '',
            ]);

            return Command::FAILURE;
        }

        //
        // Display warning for cloud provider servers
        // ----

        if ($isDigitalOceanServer) {
            $this->io->writeln('<fg=yellow>⚠ This is a DigitalOcean server.</>');
            $this->io->writeln("  Droplet ID: <fg=gray>{$server->dropletId}</>");
            $this->io->writeln('');
            $this->io->warning('This will:');
            $this->io->writeln('  • Destroy the droplet on DigitalOcean');
            $this->io->writeln('  • Remove the server from inventory');
            $this->io->writeln('');
        }

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

        /** @var bool $confirmed */
        $confirmed = $this->io->getOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );

        if (!$confirmed) {
            $this->io->warning('Cancelled deleting server');
            $this->io->writeln('');

            return Command::SUCCESS;
        }

        //
        // Destroy cloud provider resources
        // ----

        $destroyed = false;

        if ($isDigitalOceanServer && $server->dropletId !== null) {
            try {
                $this->io->promptSpin(
                    fn () => $this->digitalOcean->droplet->destroyDroplet($server->dropletId),
                    "Destroying droplet (ID: {$server->dropletId})"
                );

                $this->yay('Droplet destroyed (ID: ' . $server->dropletId . ')');
                $destroyed = true;
            } catch (\RuntimeException $e) {
                $this->nay($e->getMessage());
                $this->io->writeln('');

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

        $this->yay("Server '{$server->name}' deleted from inventory");

        if (!$destroyed) {
            $this->io->warning('Your server may still be running and incurring costs!');
            $this->io->writeln([
                '',
                'Check with your cloud provider to ensure it is fully terminated.',
                '',
            ]);
        }

        //
        // Show command replay
        // ----

        $this->showCommandReplay('server:delete', [
            'server' => $server->name,
            'yes' => $confirmed,
            'force' => true,
        ]);

        return Command::SUCCESS;
    }
}
