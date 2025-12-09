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
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
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
        // Detect current state
        // ----

        $detection = $this->executePlaybookSilently(
            $server,
            'server-firewall',
            'Detecting firewall status...',
            [
                'DEPLOYER_MODE' => 'detect',
                'DEPLOYER_PERMS' => $permissions,
            ],
        );

        if (is_int($detection)) {
            return Command::FAILURE;
        }

        /** @var bool $ufwInstalled */
        $ufwInstalled = $detection['ufw_installed'] ?? false;
        /** @var bool $ufwActive */
        $ufwActive = $detection['ufw_active'] ?? false;
        /** @var array<int, string> $ufwRules */
        $ufwRules = $detection['ufw_rules'] ?? [];
        /** @var array<int|string, string> $ports */
        $ports = $detection['ports'] ?? [];

        // Extract current UFW ports once for reuse
        $currentUfwPorts = $this->extractPortsFromRules($ufwRules);

        //
        // Display current status (F13)
        // ----

        $this->displayCurrentStatus($ufwInstalled, $ufwActive, $ufwRules, $ports);

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

        /** @var bool $force */
        $force = $input->getOption('force');

        $confirmed =  $this->io->promptConfirm(
            label: 'Are you absolutely sure?',
            default: false,
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
                'DEPLOYER_MODE' => 'apply',
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
        // Display applied rules summary
        // ----

        /** @var array<int, int> $portsAllowed */
        $portsAllowed = $result['ports_allowed'] ?? [];

        $this->displayDeets([
            'Ports allowed' => implode(', ', $portsAllowed),
            'Default policy' => 'Deny all incoming, allow all outgoing',
        ]);

        //
        // Show command replay
        // ----

        $replayOptions = [
            'server' => $server->name,
            'allow' => implode(',', $selectedPorts),
            'force' => true,
        ];

        $this->commandReplay('server:firewall', $replayOptions);

        return Command::SUCCESS;
    }

    // ----
    // Display Methods
    // ----

    /**
     * Display current UFW status (F13).
     *
     * @param bool $ufwInstalled Whether UFW is installed
     * @param bool $ufwActive Whether UFW is active
     * @param array<int, string> $ufwRules Current UFW rules
     * @param array<int|string, string> $ports Port => process mapping
     */
    private function displayCurrentStatus(bool $ufwInstalled, bool $ufwActive, array $ufwRules, array $ports): void
    {
        if (!$ufwInstalled) {
            $this->displayDeets([
                'Firewall' => 'Not installed',
            ]);

            return;
        }

        if (!$ufwActive) {
            $this->displayDeets([
                'Firewall' => 'Inactive',
            ]);

            return;
        }

        // UFW is active - show rules
        $this->displayDeets(['Firewall' => 'Active']);

        if ([] === $ufwRules) {
            $this->displayDeets(['Open Ports' => 'None']);
        } else {
            $openPorts = [];
            foreach ($this->extractPortsFromRules($ufwRules) as $port) {
                $process = $ports[$port] ?? 'unknown';
                $openPorts["Port {$port}"] = $process;
            }

            $this->displayDeets(['Open Ports' => $openPorts]);
        }
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

    /**
     * Extract port numbers from UFW rule strings.
     *
     * @param array<int, string> $rules UFW rules in format "port/proto" (e.g., "22/tcp")
     * @return array<int, int> List of port numbers
     */
    private function extractPortsFromRules(array $rules): array
    {
        $ports = [];

        foreach ($rules as $rule) {
            // Extract port from "port/proto" format
            if (preg_match('/^(\d+)/', $rule, $matches)) {
                $ports[] = (int) $matches[1];
            }
        }

        return $ports;
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
                'Ports %s are not listening services and will be ignored',
                implode(', ', $filteredPorts)
            ));
        }

        if ([] === $validPorts && [] !== $requestedPorts) {
            $this->warn('No valid listening ports specified. Only SSH port will be allowed.');
        }

        return array_values($validPorts);
    }
}
