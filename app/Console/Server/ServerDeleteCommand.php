<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\Traits\DigitalOceanCommandTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServerHelpersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:delete', description: 'Delete a server from the inventory')]
class ServerDeleteCommand extends BaseCommand
{
    use DigitalOceanCommandTrait;
    use ServerHelpersTrait;

    //
    // Configuration
    // -------------------------------------------------------------------------------

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip typing server name (use with caution)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    //
    // Execution
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->io->hr();
        $this->io->h1('Delete Server');

        //
        // Select server

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        // Get sites for this server
        $serverSites = $this->sites->findByServer($server->name);

        //
        // Display server details

        $this->io->hr();

        $this->displayServerDeets($server, $serverSites);
        $this->io->writeln('');

        if (count($serverSites) > 0) {
            $this->io->error("Cannot delete server '{$server->name}' because it has one or more sites.");

            return Command::FAILURE;
        }

        //
        // Check if DigitalOcean server and initialize API

        $isDigitalOceanServer = $this->isDigitalOceanServer($server);

        if ($isDigitalOceanServer) {
            if ($this->initializeDigitalOceanAPI() === Command::FAILURE) {
                $this->io->error('Cannot delete server: DigitalOcean API authentication failed.');
                $this->io->writeln([
                    '',
                    'You must authenticate with DigitalOcean to delete provisioned servers.',
                    'The server will not be removed from inventory to prevent orphaned cloud resources.',
                    '',
                ]);

                return Command::FAILURE;
            }
        }

        //
        // Display warning for cloud provider servers

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

        /** @var bool $forceSkip */
        $forceSkip = $input->getOption('force') ?? false;

        if (!$forceSkip) {
            $typedName = $this->io->promptText(
                label: "Type the server name '{$server->name}' to confirm deletion:",
                required: true
            );

            if ($typedName !== $server->name) {
                $this->io->error('Server name does not match. Deletion cancelled.');
                $this->io->writeln('');

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

        if ($isDigitalOceanServer && $server->dropletId !== null) {
            try {
                $this->io->promptSpin(
                    fn () => $this->digitalOcean->droplet->destroyDroplet($server->dropletId),
                    "Destroying droplet (ID: {$server->dropletId})"
                );
                $this->io->success('Droplet destroyed');
            } catch (\RuntimeException $e) {
                $this->io->error($e->getMessage());
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

        $this->servers->delete($server->name);

        $this->io->success("Server '{$server->name}' deleted successfully");
        $this->io->writeln('');

        //
        // Show command hint

        $this->io->showCommandHint('server:delete', [
            'server' => $server->name,
            'yes' => $confirmed,
            'force' => true,
        ]);

        return Command::SUCCESS;
    }
}
