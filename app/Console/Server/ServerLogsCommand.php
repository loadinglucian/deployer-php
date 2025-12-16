<?php

declare(strict_types=1);

namespace Deployer\Console\Server;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\ServerDTO;
use Deployer\Traits\LogsTrait;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\SitesTrait;
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
    use LogsTrait;
    use PlaybooksTrait;
    use ServersTrait;
    use SitesTrait;

    /**
     * @var array{
     *     options: array<string|int, string>,
     *     services: list<string>,
     *     phpVersions: list<string>,
     *     sites: list<string>,
     *     supervisors: list<array{domain: string, program: string}>
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

        $processed = $this->getProcessedServices($server);

        /** @var array<string, string> $options */
        $options = $processed['options'];

        $services = $this->io->getOptionOrPrompt(
            'service',
            fn () => $this->io->promptMultiselect(
                label: 'Which logs to view?',
                options: $options,
                default: ['system'],
                required: true,
                scroll: 15
            )
        );

        $lines = $this->io->getOptionOrPrompt(
            'lines',
            fn () => $this->io->promptText(
                label: 'Number of lines:',
                default: '50',
                validate: fn ($value) => $this->validateLineCount($value)
            )
        );

        // Handle CLI option (comma-separated string)
        if (is_string($services)) {
            $services = array_filter(
                array_map(trim(...), explode(',', $services)),
                static fn (string $s): bool => $s !== ''
            );
        }

        if (!is_array($services) || $services === []) {
            $this->nay('No services selected');

            return Command::FAILURE;
        }

        /** @var list<string> $services */

        //
        // Retrieve logs
        // ----

        $this->displayServiceLogs($server, $services, (int) $lines);

        //
        // Show command replay
        // ----

        $this->commandReplay('server:logs', [
            'server' => $server->name,
            'lines' => $lines,
            'service' => implode(',', $services),
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Process detected services, PHP versions, sites, and supervisors to build options.
     *
     * @return array{
     *     options: array<string|int, string>,
     *     services: list<string>,
     *     phpVersions: list<string>,
     *     sites: list<string>,
     *     supervisors: list<array{domain: string, program: string}>
     * }
     */
    protected function getProcessedServices(ServerDTO $server): array
    {
        if ($this->processedServices !== null) {
            return $this->processedServices;
        }

        /** @var array<string, mixed> $info */
        $info = $server->info;

        // 1. Detected listening services
        /** @var array<int, string> $ports */
        $ports = $info['ports'] ?? [];
        $detected = array_unique(array_values($ports));

        $services = [];

        foreach ($detected as $service) {
            $lower = strtolower((string) $service);

            if ($lower === 'unknown' || str_contains($lower, 'docker') || $lower === 'private-network') {
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

        // 4. Supervisor programs (from inventory)
        /** @var list<array{domain: string, program: string}> $supervisors */
        $supervisors = [];
        $serverSites = $this->sites->findByServer($server->name);

        foreach ($serverSites as $siteDTO) {
            foreach ($siteDTO->supervisors as $supervisor) {
                $supervisors[] = [
                    'domain' => $siteDTO->domain,
                    'program' => $supervisor->program,
                ];
            }
        }

        // Build options
        $options = [
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

        // Supervisor programs
        foreach ($supervisors as $sup) {
            $key = "supervisor:{$sup['domain']}/{$sup['program']}";
            $options[$key] = "Supervisor: {$sup['domain']}/{$sup['program']}";
        }

        return $this->processedServices = [
            'options' => $options,
            'services' => $services,
            'phpVersions' => $phpVersions,
            'sites' => $sites,
            'supervisors' => $supervisors,
        ];
    }

    /**
     * Display logs for selected service(s).
     *
     * @param list<string> $services Selected services
     */
    protected function displayServiceLogs(ServerDTO $server, array $services, int $lines): void
    {
        $processed = $this->getProcessedServices($server);
        /** @var list<string> $sites */
        $sites = $processed['sites'];

        foreach ($services as $service) {
            if ($service === 'system') {
                $this->retrieveServiceLogs($server, 'System', '', $lines);
            } elseif (str_starts_with($service, 'supervisor:')) {
                // Parse supervisor:{domain}/{program}
                $parts = explode('/', substr($service, 11), 2);
                if (2 === count($parts)) {
                    [$domain, $program] = $parts;
                    $fullName = "{$domain}-{$program}";
                    $this->retrieveFileLogs(
                        $server,
                        "Supervisor: {$domain}/{$program}",
                        "/var/log/supervisor/{$fullName}.log",
                        $lines
                    );
                } else {
                    $this->warn("Invalid supervisor format: '{$service}'. Expected 'supervisor:{domain}/{program}'");
                }
            } elseif (in_array($service, $sites, true)) {
                $this->retrieveFileLogs(
                    $server,
                    "Site: {$service}",
                    "/var/log/caddy/{$service}-access.log",
                    $lines
                );
            } elseif (str_starts_with($service, 'php') && str_ends_with($service, '-fpm')) {
                $this->retrieveFileLogs(
                    $server,
                    $service,
                    "/var/log/{$service}.log",
                    $lines
                );
            } elseif ('mysqld' === $service) {
                $this->retrieveFileLogs(
                    $server,
                    'MySQL',
                    '/var/log/mysql/error.log',
                    $lines
                );
            } else {
                // Generic service (journalctl)
                $this->retrieveServiceLogs($server, $service, $service, $lines);
            }
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
            $this->warn('No logs found or file does not exist.');
        }
    }

    /**
     * Try to find and display traditional log files in /var/log/.
     */
    protected function tryTraditionalLogs(ServerDTO $server, string $service, int $lines): void
    {
        try {
            $searchPatterns = $this->getLogSearchPatterns($service);
            $namePatterns = implode(' -o ', array_map(
                static fn (string $p): string => "-iname '*{$p}*'",
                $searchPatterns
            ));

            $findCommand = "find /var/log -type f \\( {$namePatterns} \\) 2>/dev/null | head -5";
            $result = $this->ssh->executeCommand($server, $findCommand);

            if (0 === $result['exit_code'] && '' !== trim($result['output'])) {
                $logFiles = array_filter(array_map(trim(...), explode("\n", trim($result['output']))));

                foreach ($logFiles as $logFile) {
                    $logContent = $this->readLogFile($server, $logFile, $lines);

                    if (null !== $logContent) {
                        $this->out("<fg=bright-black>From {$logFile}:</>");
                        $this->io->write($this->highlightErrors($logContent), true);
                        $this->out('───');

                        return;
                    }
                }
            }
        } catch (\RuntimeException) {
            // Fall through to warning
        }

        $this->warn("No {$service} logs found");
    }

    /**
     * Get log file search patterns for a service name.
     *
     * @return list<string>
     */
    protected function getLogSearchPatterns(string $service): array
    {
        $serviceLower = strtolower($service);

        return match ($serviceLower) {
            'mysqld' => ['mysql'],
            default => [$serviceLower],
        };
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
            'mysqld' => ['mysql', 'mysql.service'],
            'sshd' => ['ssh', 'ssh.service'],
            'supervisor' => ['supervisor', 'supervisord', 'supervisord.service'],
            'systemd-resolve' => ['systemd-resolved', 'systemd-resolved.service'],
            default => [],
        };

        return array_unique(array_merge($patterns, $variations));
    }
}
