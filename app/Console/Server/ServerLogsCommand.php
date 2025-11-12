<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Console\Server;

use Bigpixelrocket\DeployerPHP\Contracts\BaseCommand;
use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\Traits\PlaybooksTrait;
use Bigpixelrocket\DeployerPHP\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:logs',
    description: 'View server logs (system and detected services)'
)]
class ServerLogsCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $processedServices = null;

    // ---- Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('lines', 'n', InputOption::VALUE_REQUIRED, 'Number of lines to retrieve');
        $this->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Service name (all|system|detected service name)');
    }

    // ---- Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->heading('Server Logs');

        //
        // Select server & display details
        // ----

        $server = $this->selectServer();

        if (is_int($server)) {
            return $server;
        }

        $this->displayServerDeets($server);

        //
        // Get server info (verifies SSH connection and validates distribution & permissions)
        // ----

        $info = $this->serverInfo($server);

        if (is_int($info)) {
            return $info;
        }

        //
        // Get user input
        // ----

        $lines = $this->io->getOptionOrPrompt(
            'lines',
            fn () => $this->io->promptText(
                label: 'Number of lines:',
                default: '10',
                validate: fn ($value) => is_numeric($value) && (int) $value > 0 ? null : 'Must be a positive number'
            )
        );

        $processed = $this->getProcessedServices($info);

        /** @var array<string, string> $options */
        $options = $processed['options'];

        $service = $this->io->getOptionOrPrompt(
            'service',
            fn () => $this->io->promptSelect(
                label: 'Which service logs?',
                options: $options,
                default: 'all'
            )
        );

        //
        // Retrieve logs
        // ----

        $this->displayServiceLogs($server, (string) $service, (int) $lines, $info);

        //
        // Show command replay
        // ----

        $this->showCommandReplay('server:logs', [
            'server' => $server->name,
            'lines' => $lines,
            'service' => $service,
        ]);

        return Command::SUCCESS;
    }

    // ---- Helpers
    // ----

    /**
     * Process detected services and build options for user selection.
     *
     * Consolidates Docker-related processes and caches results for reuse.
     *
     * @param array<string, mixed> $info Server information from server-info playbook
     * @return array<string, mixed>
     */
    protected function getProcessedServices(array $info): array
    {
        if ($this->processedServices !== null) {
            return $this->processedServices;
        }

        /** @var array<int, string> $ports */
        $ports = $info['ports'] ?? [];
        $detected = array_unique(array_values($ports));

        $services = [];
        $hasDocker = false;

        foreach ($detected as $service) {
            $lower = strtolower($service);

            if ($lower === 'unknown') {
                continue;
            }

            if (str_contains($lower, 'docker') || $lower === 'private-network') {
                $hasDocker = true;
                continue;
            }

            $services[] = $service;
        }

        $options = [
            'all' => 'All services',
            'system' => 'System logs',
        ];

        foreach ($services as $service) {
            $options[strtolower($service)] = $service;
        }

        if ($hasDocker) {
            $options['docker'] = 'docker';
        }

        return $this->processedServices = [
            'options' => $options,
            'services' => $services,
            'hasDocker' => $hasDocker,
        ];
    }

    /**
     * Display logs for selected service(s).
     *
     * @param array<string, mixed> $info Server information
     */
    protected function displayServiceLogs(ServerDTO $server, string $service, int $lines, array $info): void
    {
        if ($service === 'all') {
            $processed = $this->getProcessedServices($info);

            $this->retrieveServiceLogs($server, 'System', '', $lines);

            /** @var array<int, string> $services */
            $services = $processed['services'];
            foreach ($services as $serviceName) {
                $this->retrieveServiceLogs($server, $serviceName, $serviceName, $lines);
            }

            /** @var bool $hasDocker */
            $hasDocker = $processed['hasDocker'];
            if ($hasDocker) {
                $this->retrieveServiceLogs($server, 'docker', 'docker', $lines);
            }
        } elseif ($service === 'system') {
            $this->retrieveServiceLogs($server, 'System', '', $lines);
        } else {
            $this->retrieveServiceLogs($server, $service, $service, $lines);
        }
    }

    /**
     * Retrieve service logs via journalctl.
     */
    protected function retrieveServiceLogs(ServerDTO $server, string $service, string $unit, int $lines): void
    {
        $this->io->writeln([
            "<fg=cyan>{$service} Logs</>",
            '',
        ]);

        try {
            if ($unit === '') {
                $command = sprintf('journalctl -n %d --no-pager 2>&1', $lines);
            } else {
                $unitArgs = array_map(
                    static fn (string $name): string => '-u ' . escapeshellarg($name),
                    $this->getServiceNamePatterns($unit)
                );
                $command = sprintf('journalctl %s -n %d --no-pager 2>&1', implode(' ', $unitArgs), $lines);
            }

            $result = $this->ssh->executeCommand($server, $command);
            $output = trim($result['output']);

            $serviceNotFound = str_contains($output, 'No data available') ||
                              str_contains($output, 'Failed to add filter');
            $noData = $output === '' || $output === '-- No entries --';

            if ($result['exit_code'] !== 0 && !$serviceNotFound) {
                $this->nay("Failed to retrieve {$service} logs");
                $this->io->writeln($output);
                $this->io->writeln('');

                return;
            }

            if ($serviceNotFound || $noData) {
                $this->tryTraditionalLogs($server, $service, $lines);
                $this->io->writeln('');

                return;
            }

            $this->io->writeln($output);
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());
        }

        $this->io->writeln('');
    }

    /**
     * Try to find and display traditional log files in /var/log/.
     */
    protected function tryTraditionalLogs(ServerDTO $server, string $service, int $lines): void
    {
        try {
            $serviceLower = strtolower($service);
            $findCommand = "find /var/log -type f -iname '*{$serviceLower}*' 2>/dev/null | head -5";
            $result = $this->ssh->executeCommand($server, $findCommand);

            if ($result['exit_code'] !== 0 || trim($result['output']) === '') {
                $this->io->writeln("<fg=yellow>No {$service} logs found</>");

                return;
            }

            $logFiles = array_filter(array_map(trim(...), explode("\n", trim($result['output']))));

            foreach ($logFiles as $logFile) {
                $logContent = $this->readLogFile($server, $logFile, $lines);

                if ($logContent !== null) {
                    $this->io->writeln([
                        "<fg=bright-black>From {$logFile}:</>",
                        '',
                        $logContent,
                    ]);

                    return;
                }
            }

            $this->io->writeln("<fg=yellow>No {$service} logs found</>");
        } catch (\RuntimeException) {
            $this->io->writeln("<fg=yellow>No {$service} logs found</>");
        }
    }

    /**
     * Attempt to read a log file from the server.
     */
    protected function readLogFile(ServerDTO $server, string $logFile, int $lines): ?string
    {
        try {
            $result = $this->ssh->executeCommand($server, "tail -n {$lines} {$logFile} 2>/dev/null");

            if ($result['exit_code'] === 0 && trim($result['output']) !== '') {
                return trim($result['output']);
            }

            return null;
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Get common systemd service name patterns for a process.
     *
     * @return array<int, string>
     */
    protected function getServiceNamePatterns(string $process): array
    {
        $patterns = [
            $process,                    // As-is
            "{$process}.service",        // With .service
        ];

        // Handle common naming variations
        $variations = match ($process) {
            'sshd' => ['ssh', 'ssh.service'],
            'systemd-resolve' => ['systemd-resolved', 'systemd-resolved.service'],
            'docker', 'docker-proxy' => ['docker', 'docker.service', 'dockerd', 'dockerd.service'],
            default => [],
        };

        return array_unique(array_merge($patterns, $variations));
    }
}
