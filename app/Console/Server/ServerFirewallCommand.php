<?php

declare(strict_types=1);

namespace Deployer\Console\Server;

use Deployer\Contracts\BaseCommand;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:firewall',
    description: 'Manage UFW firewall rules on the server'
)]
class ServerFirewallCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;

    /**
     * Default ports to pre-check if detected as listening.
     */
    private const DEFAULT_PORTS = [80, 443];

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('allow', null, InputOption::VALUE_REQUIRED, 'Comma-separated ports to allow (e.g., 80,443,3306)');
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip Yes/No confirmation prompt');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Server Firewall');

        //
        // Select server
        // ----

        $server = $this->selectServer();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        $sshPort = $server->port;

        /** @var string $permissions */
        $permissions = $server->info['permissions'];

        //
        // Extract firewall state from server info
        // ----

        $info = $server->info;

        /** @var array<int, string> $ufwRules */
        $ufwRules = $info['ufw_rules'] ?? [];
        /** @var array<int|string, string> $ports */
        $ports = $info['ports'] ?? [];

        // Extract current UFW ports once for reuse
        $currentUfwPorts = $this->extractPortsFromRules($ufwRules);

        //
        // Display current status
        // ----

        $this->displayFirewallDeets($info);

        //
        // Build port options for selection
        // ----

        // Convert ports to array<int, string> format (port => process)
        /** @var array<int, string> $listeningPorts */
        $listeningPorts = [];
        foreach ($ports as $port => $process) {
            $listeningPorts[(int) $port] = (string) $process;
        }

        // Filter SSH port from selectable options (F4)
        $selectablePorts = $this->filterSshPort($listeningPorts, $sshPort);

        //
        // Handle --allow option (F10, F11)
        // ----

        /** @var string|null $allowOption */
        $allowOption = $input->getOption('allow');

        if (null !== $allowOption) {
            $selectedPorts = $this->parseAndFilterAllowOption($allowOption, $selectablePorts);
        } else {
            //
            // Interactive port selection (F3, F5)
            // ----

            if ([] === $selectablePorts) {
                $this->info('No additional ports detected besides SSH.');
                $selectedPorts = [];
            } else {
                // Get default pre-selected ports (F5)
                $defaultPorts = $this->getDefaultPorts(array_keys($selectablePorts), $currentUfwPorts);

                // Prompt user for port selection (F3)
                $selectedPorts = $this->promptPortSelection($selectablePorts, $defaultPorts);
            }
        }

        //
        // Confirmation summary (F6)
        // ----

        /** @var bool $confirmed */
        $confirmed = $this->io->getOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );

        if (!$confirmed) {
            $this->warn('Cancelled firewall configuration');

            return Command::SUCCESS;
        }

        //
        // Apply firewall rules (F7, F8, F9)
        // ----

        // Always prepend SSH port to allowed ports (defense in depth)
        $allowedPorts = array_unique(array_merge([$sshPort], $selectedPorts));
        sort($allowedPorts);

        $result = $this->executePlaybook(
            $server,
            'server-firewall',
            'Configuring firewall...',
            [
                'DEPLOYER_PERMS' => $permissions,
                'DEPLOYER_SSH_PORT' => (string) $sshPort,
                'DEPLOYER_ALLOWED_PORTS' => implode(',', $allowedPorts),
            ],
        );

        if (is_int($result)) {
            return Command::FAILURE;
        }

        $this->yay('Firewall configured successfully');

        //
        // Show command replay
        // ----

        $replayOptions = [
            'server' => $server->name,
            'allow' => implode(',', $selectedPorts),
            'yes' => true,
        ];

        $this->commandReplay('server:firewall', $replayOptions);

        return Command::SUCCESS;
    }

    // ----
    // Port Filtering Methods
    // ----

    /**
     * Filter SSH port from selectable ports (F4).
     *
     * SSH port should never appear in the multi-select list as it's always allowed.
     *
     * @param array<int, string> $ports Port => process mapping
     * @param int $sshPort SSH port to filter out
     * @return array<int, string> Filtered ports
     */
    private function filterSshPort(array $ports, int $sshPort): array
    {
        unset($ports[$sshPort]);

        return $ports;
    }

    /**
     * Get default ports to pre-select (F5).
     *
     * Pre-selects DEFAULT_PORTS (80, 443) if they're listening, plus any ports
     * currently allowed in UFW rules.
     *
     * @param array<int, int> $detectedPorts List of detected listening ports
     * @param array<int, int> $currentUfwPorts List of ports currently allowed by UFW
     * @return array<int, int> Ports to pre-select
     */
    private function getDefaultPorts(array $detectedPorts, array $currentUfwPorts): array
    {
        $defaults = [];

        // Add default ports (80, 443) if they're listening
        foreach (self::DEFAULT_PORTS as $port) {
            if (in_array($port, $detectedPorts, true)) {
                $defaults[] = $port;
            }
        }

        // Add currently allowed UFW ports if they're listening
        foreach ($currentUfwPorts as $port) {
            if (in_array($port, $detectedPorts, true) && !in_array($port, $defaults, true)) {
                $defaults[] = $port;
            }
        }

        return $defaults;
    }

    // ----
    // Interactive Methods
    // ----

    /**
     * Prompt user to select ports to allow (F3).
     *
     * @param array<int, string> $ports Port => process mapping
     * @param array<int, int> $defaultPorts Ports to pre-select
     * @return array<int, int> Selected port numbers
     */
    private function promptPortSelection(array $ports, array $defaultPorts): array
    {
        // Build options array with display format "port (process)"
        $options = [];
        foreach ($ports as $port => $process) {
            $options[$port] = "{$port} ({$process})";
        }

        // Get selected ports from user
        /** @var array<int, int> $selected */
        $selected = $this->io->promptMultiselect(
            label: 'Select only the ports you want to be open (the SSH port will always remain open):',
            options: $options,
            default: $defaultPorts,
            scroll: 10,
            hint: 'Use space to toggle, enter to confirm'
        );

        return $selected;
    }

    // ----
    // CLI Option Parsing
    // ----

    /**
     * Parse --allow option and filter to detected ports only (F10, F11).
     *
     * @param string $allowOption Comma-separated ports from --allow
     * @param array<int, string> $selectablePorts Detected listening ports
     * @return array<int, int> Valid port numbers
     */
    private function parseAndFilterAllowOption(string $allowOption, array $selectablePorts): array
    {
        // Parse comma-separated ports
        $requestedPorts = array_filter(
            array_map(
                fn (string $p): int => (int) trim($p),
                explode(',', $allowOption)
            ),
            fn (int $port): bool => $port > 0 && $port <= 65535
        );

        // Get list of valid listening ports
        $listeningPortNumbers = array_keys($selectablePorts);

        // Filter to only listening ports
        $validPorts = array_filter(
            $requestedPorts,
            fn (int $port): bool => in_array($port, $listeningPortNumbers, true)
        );

        // Report filtered ports (F11)
        $filteredPorts = array_diff($requestedPorts, $validPorts);

        if ([] !== $filteredPorts) {
            $this->warn(sprintf(
                'Ports %s are not listening services and will be ignored.',
                implode(', ', $filteredPorts)
            ));
        }

        if ([] === $validPorts && [] !== $requestedPorts) {
            $this->warn('No valid listening ports specified. Only SSH port will be allowed.');
        }

        return array_values($validPorts);
    }
}
