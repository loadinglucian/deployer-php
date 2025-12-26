<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Server;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\ServicesTrait;
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
    use ServicesTrait;

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

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        $this->displayFirewallDeets($server->info);

        //
        // Get firewall state and open ports
        // ----

        /** @var array<int, string> $ufwRules */
        $ufwRules = $server->info['ufw_rules'] ?? [];
        $ufwPorts = $this->extractPortsFromRules($ufwRules);

        /** @var array<int, string> $openPorts */
        $openPorts = $server->info['ports'] ?? [];
        $selectablePorts = $openPorts;
        unset($selectablePorts[$server->port]); // Can't select the SSH port (it's always allowed)

        if ([] === $selectablePorts) {
            $this->warn('No additional services detected besides SSH.');
            return Command::SUCCESS;
        }

        //
        // Gather firewall configuration
        // ----

        $ufwDeets = $this->gatherFirewallDeets($selectablePorts, $ufwPorts);

        if (is_int($ufwDeets)) {
            return Command::FAILURE;
        }

        [
            'selectedPorts' => $selectedPorts,
            'confirmed' => $confirmed
        ] = $ufwDeets;

        if (!$confirmed) {
            $this->warn('Cancelled firewall configuration');

            return Command::SUCCESS;
        }

        //
        // Apply firewall rules
        // ----

        $result = $this->executePlaybook(
            $server,
            'server-firewall',
            'Configuring firewall...',
            [
                'DEPLOYER_ALLOWED_PORTS' => implode(',', $selectedPorts),
            ],
        );

        if (is_int($result)) {
            return Command::FAILURE;
        }

        $this->yay('Firewall configured successfully');

        //
        // Show command replay
        // ----

        $this->commandReplay('server:firewall', [
            'server' => $server->name,
            'allow' => implode(',', $selectedPorts),
            'yes' => true,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Gather firewall configuration from CLI options or interactive prompts.
     *
     * @param array<int, string> $selectablePorts Port => process mapping
     * @param array<int, int> $ufwPorts Currently allowed UFW ports
     * @return array{selectedPorts: array<int, int>, confirmed: bool}|int
     */
    private function gatherFirewallDeets(array $selectablePorts, array $ufwPorts): array|int
    {
        try {
            // Pre-select: common HTTP/HTTPS ports if listening + current UFW allowed ports
            $defaultPorts = array_values(array_unique(array_filter(
                [80, 443, ...$ufwPorts],
                fn (int $port): bool => isset($selectablePorts[$port])
            )));

            // Build options array with display format "port (Service Label)"
            $options = [];
            foreach ($selectablePorts as $port => $process) {
                $options[$port] = $this->formatPortService($port, $process);
            }

            /** @var array<int, int>|string $selectedPorts */
            $selectedPorts = $this->io->getValidatedOptionOrPrompt(
                'allow',
                fn ($validate) => $this->io->promptMultiselect(
                    label: 'Select ports to allow (SSH always included):',
                    options: $options,
                    default: $defaultPorts,
                    scroll: 10,
                    hint: 'Use space to toggle, enter to confirm',
                    validate: $validate
                ),
                fn ($value) => $this->validateAllowInput($value, $selectablePorts)
            );

            // Normalize: CLI gives comma-string, prompt gives array
            if (is_string($selectedPorts)) {
                $selectedPorts = array_values(array_filter(array_map(
                    fn (string $p): int => (int) trim($p),
                    explode(',', $selectedPorts)
                )));
            }

            /** @var array<int, int> $selectedPorts */
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());
            return Command::FAILURE;
        }

        $confirmed = $this->io->getBooleanOptionOrPrompt(
            'yes',
            fn (): bool => $this->io->promptConfirm(
                label: 'Are you absolutely sure?',
                default: false
            )
        );

        return [
            'selectedPorts' => $selectedPorts,
            'confirmed' => $confirmed,
        ];
    }

    // ----
    // Validation
    // ----

    /**
     * Validate --allow option input.
     *
     * @param array<int, string> $selectablePorts Valid port => process mapping
     */
    private function validateAllowInput(mixed $value, array $selectablePorts): ?string
    {
        if (!is_string($value) && !is_array($value)) {
            return 'Ports must be a comma-separated string or array';
        }

        // Parse if string (CLI), keep as-is if array (prompt)
        $ports = is_string($value)
            ? array_filter(array_map(fn ($p) => (int) trim($p), explode(',', $value)))
            : $value;

        if ([] === $ports) {
            return 'At least one port must be selected';
        }

        // Validate port range
        /** @var int $port */
        foreach ($ports as $port) {
            if ($port < 1 || $port > 65535) {
                return sprintf('Invalid port number: %d', $port);
            }
        }

        // Validate ports are in selectable list
        $validPorts = array_keys($selectablePorts);
        $invalidPorts = array_diff($ports, $validPorts);

        if ([] !== $invalidPorts) {
            return sprintf(
                'Ports not listening: %s. Available: %s',
                implode(', ', $invalidPorts),
                implode(', ', $validPorts)
            );
        }

        return null;
    }
}
