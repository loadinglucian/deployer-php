<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\Enums\Distribution;
use Bigpixelrocket\DeployerPHP\Traits\PlaybooksTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:info',
    description: 'Display server information'
)]
class ServerInfoCommand extends BaseCommand
{
    use ServersTrait;
    use PlaybooksTrait;

    // -------------------------------------------------------------------------------
    //
    // Configuration
    //
    // -------------------------------------------------------------------------------

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
    }

    // -------------------------------------------------------------------------------
    //
    // Execution
    //
    // -------------------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->heading('Server Information');

        //
        // Select server & display details
        // -------------------------------------------------------------------------------

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        $this->displayServerDeets($server);

        //
        // Get and display server information
        // -------------------------------------------------------------------------------

        $info = $this->getServerInfo($server);

        if (is_int($info)) {
            return $info;
        }

        $this->displayServerInfo($info);

        //
        // Show command replay
        // -------------------------------------------------------------------------------

        $this->showCommandReplay('server:info', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------------
    //
    // Helpers
    //
    // -------------------------------------------------------------------------------

    /**
     * Get server information by executing server-info playbook.
     *
     * @param ServerDTO $server Server to get information for
     * @return array<string, mixed>|int Returns parsed server info or failure code on failure
     */
    protected function getServerInfo(ServerDTO $server): array|int
    {
        return $this->executePlaybook(
            $server,
            'server-info',
            'Retrieving server information...',
        );
    }

    /**
     * Display formatted server information.
     *
     * @param array<string, mixed> $info
     */
    protected function displayServerInfo(array $info): void
    {
        /** @var string $distroSlug */
        $distroSlug = $info['distro'] ?? 'unknown';
        $distribution = Distribution::tryFrom($distroSlug);
        $distroName = $distribution?->displayName() ?? 'Unknown';

        $permissionsText = match ($info['permissions'] ?? 'none') {
            'root' => 'root',
            'sudo' => 'sudo',
            default => 'insufficient',
        };

        $deets = [
            'Distro' => $distroName,
            'User' => $permissionsText,
        ];

        $this->io->displayDeets($deets);
        $this->io->writeln('');

        $services = [];

        // Add listening ports if any
        if (isset($info['ports']) && is_array($info['ports']) && count($info['ports']) > 0) {
            $portsList = [];
            foreach ($info['ports'] as $port => $process) {
                if (is_numeric($port) && is_string($process)) {
                    $portsList[] = "Port {$port}: {$process}";
                }
            }
            if (count($portsList) > 0) {
                $services = $portsList;
            }
        }

        $this->io->displayDeets(['Services' => $services]);
        $this->io->writeln('');
    }

}
