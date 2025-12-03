<?php

declare(strict_types=1);

namespace Deployer\Console\Server;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\ServerDTO;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:logs',
    description: 'View server logs (system, PHP-FPM, sites, and detected services)'
)]
class ServerLogsCommand extends BaseCommand
{
    use PlaybooksTrait;
    use ServersTrait;

    /**
     * @var array{
     *     options: array<string|int, string>,
     *     services: list<string>,
     *     phpVersions: list<string>,
     *     sites: list<string>,
     *     hasDocker: bool
     * }|null
     */
    private ?array $processedServices = null;

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('lines', 'n', InputOption::VALUE_REQUIRED, 'Number of lines to retrieve');
        $this->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Service name (all|system|php-fpm|site|detected service name)');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Server Logs');

        //
        // Select server & display details
        // ----

        $server = $this->selectServer();

        if (is_int($server) || $server->info === null) {
            return Command::FAILURE;
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

        $processed = $this->getProcessedServices($server->info);

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

        $this->displayServiceLogs($server, (string) $service, (int) $lines, $server->info);

        //
        // Show command replay
        // ----

        $this->commandReplay('server:logs', [
            'server' => $server->name,
            'lines' => $lines,
            'service' => $service,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Process detected services, PHP versions, and sites to build options.
     *
     * @param array<string, mixed> $info Server information from server-info playbook
     * @return array{
     *     options: array<string|int, string>,
     *     services: list<string>,
     *     phpVersions: list<string>,
     *     sites: list<string>,
     *     hasDocker: bool
     * }
     */
    protected function getProcessedServices(array $info): array
    {
        if ($this->processedServices !== null) {
            return $this->processedServices;
        }

        // 1. Detected listening services
        /** @var array<int, string> $ports */
        $ports = $info['ports'] ?? [];
        $detected = array_unique(array_values($ports));

        $services = [];
        $hasDocker = false;

        foreach ($detected as $service) {
            $lower = strtolower((string) $service);

            if ($lower === 'unknown') {
                continue;
            }

            if (str_contains($lower, 'docker') || $lower === 'private-network') {
                $hasDocker = true;
                continue;
            }

            $services[] = (string) $service;
        }

        // 2. PHP Versions (PHP-FPM)
        $phpVersions = [];
        if (isset($info['php']) && is_array($info['php']) && isset($info['php']['versions']) && is_array($info['php']['versions'])) {
            foreach ($info['php']['versions'] as $versionData) {
                $version = null;
                if (is_array($versionData) && isset($versionData['version'])) {
                    /** @var string|int|float $rawVersion */
                    $rawVersion = $versionData['version'];
                    $version = (string) $rawVersion;
                } elseif (is_string($versionData) || is_numeric($versionData)) {
                    $version = (string) $versionData;
                }

                if ($version !== null) {
                    $phpVersions[] = $version;
                }
            }
        }

        // 3. Sites
        /** @var list<string> $sites */
        $sites = [];
        if (isset($info['sites_config']) && is_array($info['sites_config'])) {
            $sites = array_map(strval(...), array_keys($info['sites_config']));
        }

        // Build options
        $options = [
            'all' => 'All logs (System, Services, PHP, Sites)',
            'system' => 'System logs',
        ];

        // Detected services (Caddy, SSH, etc.)
        foreach ($services as $service) {
            $options[strtolower($service)] = $service;
        }

        // PHP-FPM services
        foreach ($phpVersions as $version) {
            $serviceName = "php{$version}-fpm";
            $options[$serviceName] = "PHP {$version} FPM";
        }

        // Sites
        foreach ($sites as $site) {
            $options[$site] = "Site: {$site}";
        }

        if ($hasDocker) {
            $options['docker'] = 'Docker';
        }

        return $this->processedServices = [
            'options' => $options,
            'services' => $services,
            'phpVersions' => $phpVersions,
            'sites' => $sites,
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
        $processed = $this->getProcessedServices($info);
        /** @var array<int, string> $detectedServices */
        $detectedServices = $processed['services'];
        /** @var array<int, string> $phpVersions */
        $phpVersions = $processed['phpVersions'];
        /** @var array<int, string> $sites */
        $sites = $processed['sites'];
        /** @var bool $hasDocker */
        $hasDocker = $processed['hasDocker'];

        if ($service === 'all') {
            // 1. System Logs
            $this->retrieveServiceLogs($server, 'System', '', $lines);

            // 2. Detected Services (Caddy, SSH, etc.)
            foreach ($detectedServices as $serviceName) {
                $lower = strtolower($serviceName);
                if (str_starts_with($lower, 'php') && str_ends_with($lower, '-fpm')) {
                    // Prefer file-based PHP-FPM logs handled below
                    continue;
                }

                $this->retrieveServiceLogs($server, $serviceName, $serviceName, $lines);
            }

            // 3. PHP-FPM Logs
            foreach ($phpVersions as $version) {
                $this->retrieveFileLogs(
                    $server,
                    "PHP {$version} FPM",
                    "/var/log/php{$version}-fpm.log",
                    $lines
                );
            }

            // 4. Site Logs
            foreach ($sites as $site) {
                $this->retrieveFileLogs(
                    $server,
                    "Site: {$site}",
                    "/var/log/caddy/{$site}-access.log",
                    $lines
                );
            }

            // 5. Docker
            if ($hasDocker) {
                $this->retrieveServiceLogs($server, 'Docker', 'docker', $lines);
            }

        } elseif ($service === 'system') {
            $this->retrieveServiceLogs($server, 'System', '', $lines);
        } elseif (in_array($service, $sites, true)) {
            // Specific Site Log
            $this->retrieveFileLogs(
                $server,
                "Site: {$service}",
                "/var/log/caddy/{$service}-access.log",
                $lines
            );
        } elseif (str_starts_with($service, 'php') && str_ends_with($service, '-fpm')) {
            // Specific PHP-FPM Log
            // Service name format: php8.3-fpm
            // Check if it's a file log or system service (usually both, but we prefer file for PHP-FPM)
            $this->retrieveFileLogs(
                $server,
                $service,
                "/var/log/{$service}.log",
                $lines
            );
        } else {
            // Generic Service (journalctl)
            $this->retrieveServiceLogs($server, $service, $service, $lines);
        }
    }

    /**
     * Retrieve service logs via journalctl.
     */
    protected function retrieveServiceLogs(ServerDTO $server, string $service, string $unit, int $lines): void
    {
        $this->h2($service);

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
                $this->io->write($this->highlightErrors($output), true);
                $this->out('───');

                return;
            }

            if ($serviceNotFound || $noData) {
                $this->tryTraditionalLogs($server, $service, $lines);

                return;
            }

            $this->io->write($this->highlightErrors($output), true);
            $this->out('───');
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());
        }
    }

    /**
     * Retrieve logs from a specific file.
     */
    protected function retrieveFileLogs(ServerDTO $server, string $title, string $filepath, int $lines): void
    {
        $this->h2($title);
        $this->out("<|gray>File: {$filepath}</>");

        $content = $this->readLogFile($server, $filepath, $lines);

        if ($content !== null) {
            $this->io->write($this->highlightErrors($content), true);
            $this->out('───');
        } else {
            $this->out('<fg=yellow>No logs found or file does not exist.</>');
        }
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
                $this->out("<fg=yellow>No {$service} logs found</>");

                return;
            }

            $logFiles = array_filter(array_map(trim(...), explode("\n", trim($result['output']))));

            foreach ($logFiles as $logFile) {
                $logContent = $this->readLogFile($server, $logFile, $lines);

                if ($logContent !== null) {
                    $this->out("<fg=bright-black>From {$logFile}:</>");
                    $this->io->write($this->highlightErrors($logContent), true);
                    $this->out('───');

                    return;
                }
            }

            $this->out("<fg=yellow>No {$service} logs found</>");
        } catch (\RuntimeException) {
            $this->out("<fg=yellow>No {$service} logs found</>");
        }
    }

    /**
     * Attempt to read a log file from the server.
     */
    protected function readLogFile(ServerDTO $server, string $logFile, int $lines): ?string
    {
        try {
            $safeLogFile = escapeshellarg($logFile);
            $result = $this->ssh->executeCommand($server, "tail -n {$lines} {$safeLogFile} 2>/dev/null");

            if ($result['exit_code'] === 0 && trim($result['output']) !== '') {
                return trim($result['output']);
            }

            return null;
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Highlight error keywords in log content.
     */
    protected function highlightErrors(string $content): string
    {
        // 1. Text keywords (substring match)
        $textKeywords = [
            'error',
            'exception',
            'fail',
            'failed',
            'fatal',
            'panic',
        ];

        // 2. Numeric status codes (regex word boundary match)
        // Matches 500, 502, 503, 504 as distinct words
        $statusPattern = '/\b(500|502|503|504)\b/';

        $lines = explode("\n", $content);
        $processedLines = [];

        foreach ($lines as $line) {
            $lowerLine = strtolower($line);
            $hasError = false;

            // Check text keywords
            foreach ($textKeywords as $keyword) {
                if (str_contains($lowerLine, $keyword)) {
                    $hasError = true;
                    break;
                }
            }

            // Check numeric status codes if no text error found yet
            if (!$hasError && preg_match($statusPattern, $line)) {
                $hasError = true;
            }

            if ($hasError) {
                // Highlight the entire line in red
                $processedLines[] = "<fg=red>{$line}</>";
            } else {
                $processedLines[] = $line;
            }
        }

        return implode("\n", $processedLines);
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
