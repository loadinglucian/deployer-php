<?php

declare(strict_types=1);

namespace Deployer\Console\Server;

use Deployer\Contracts\BaseCommand;
use Deployer\DTOs\ServerDTO;
use Deployer\Exceptions\ValidationException;
use Deployer\Traits\LogsTrait;
use Deployer\Traits\PlaybooksTrait;
use Deployer\Traits\ServersTrait;
use Deployer\Traits\ServicesTrait;
use Deployer\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'server:logs',
    description: 'View server logs (system, services, sites, and supervisors)'
)]
class ServerLogsCommand extends BaseCommand
{
    use LogsTrait;
    use PlaybooksTrait;
    use ServersTrait;
    use ServicesTrait;
    use SitesTrait;

    /**
     * Static log sources always available on provisioned servers.
     *
     * @var array<string, array{label: string, type: string, unit?: string|null, path?: string}>
     */
    private const STATIC_SOURCES = [
        'system' => ['label' => 'System logs', 'type' => 'journalctl', 'unit' => null],
        'supervisor' => ['label' => 'Supervisor', 'type' => 'journalctl', 'unit' => 'supervisor'],
        'cron' => ['label' => 'Cron', 'type' => 'journalctl', 'unit' => 'cron'],
    ];

    /**
     * Port-detected service log configurations (key = process name from ss/netstat).
     *
     * Labels are derived from ServicesTrait::getServiceLabel().
     * Type 'both' shows journalctl service logs AND file-based error logs.
     *
     * @var array<string, array{type: string, unit?: string, path?: string}>
     */
    private const PORT_SOURCES = [
        'caddy' => ['type' => 'journalctl', 'unit' => 'caddy'],
        'mariadb' => ['type' => 'both', 'unit' => 'mariadb', 'path' => '/var/log/mysql/error.log'],
        'memcached' => ['type' => 'both', 'unit' => 'memcached', 'path' => '/var/log/memcached.log'],
        'mysqld' => ['type' => 'both', 'unit' => 'mysql', 'path' => '/var/log/mysql/error.log'],
        'postgres' => ['type' => 'both', 'unit' => 'postgresql', 'path' => '/var/log/postgresql/postgresql.log'],
        'redis-server' => ['type' => 'both', 'unit' => 'redis-server', 'path' => '/var/log/redis/redis-server.log'],
        'sshd' => ['type' => 'journalctl', 'unit' => 'ssh'],
        'valkey-server' => ['type' => 'both', 'unit' => 'valkey-server', 'path' => '/var/log/valkey/valkey-server.log'],
    ];

    // ----
    // Configuration
    // ----

    protected function configure(): void
    {
        parent::configure();

        $this->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server name');
        $this->addOption('lines', 'n', InputOption::VALUE_REQUIRED, 'Number of lines to retrieve');
        $this->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Service(s) to view (comma-separated)');
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Server Logs');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        //
        // Build log options
        // ----

        $options = $this->buildLogOptions($server);

        //
        // Get user input
        // ----

        try {
            $services = $this->io->getValidatedOptionOrPrompt(
                'service',
                fn ($validate) => $this->io->promptMultiselect(
                    label: 'Which logs to view?',
                    options: $options,
                    default: ['system'],
                    required: true,
                    scroll: 15,
                    validate: $validate
                ),
                fn ($value) => $this->validateServicesInput($value, $options)
            );

            /** @var string $lines */
            $lines = $this->io->getValidatedOptionOrPrompt(
                'lines',
                fn ($validate) => $this->io->promptText(
                    label: 'Number of lines:',
                    default: '50',
                    validate: $validate
                ),
                fn ($value) => $this->validateLineCount($value)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        //
        // Normalize services input
        // ----

        /** @var list<string> $serviceKeys */
        $serviceKeys = is_string($services)
            ? array_filter(array_map(trim(...), explode(',', $services)))
            : $services;

        //
        // Display logs
        // ----

        $this->displayLogs($server, $serviceKeys, (int) $lines);

        //
        // Command replay
        // ----

        $this->commandReplay('server:logs', [
            'server' => $server->name,
            'lines' => $lines,
            'service' => implode(',', $serviceKeys),
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Build selectable log options from server info.
     *
     * @return array<string, string>
     */
    protected function buildLogOptions(ServerDTO $server): array
    {
        /** @var array<string, mixed> $info */
        $info = $server->info;
        $options = [];

        //
        // Static sources (always available)

        foreach (self::STATIC_SOURCES as $key => $source) {
            $options[$key] = $source['label'];
        }

        //
        // Port-detected services

        /** @var array<int, string> $ports */
        $ports = $info['ports'] ?? [];

        foreach (array_unique(array_values($ports)) as $process) {
            $key = strtolower((string) $process);

            if (isset(self::PORT_SOURCES[$key])) {
                $options[$key] = $this->getServiceLabel($key);
            }
        }

        //
        // PHP-FPM versions

        /** @var array<mixed, mixed> $phpData */
        $phpData = is_array($info['php'] ?? null) ? $info['php'] : [];

        if (isset($phpData['versions']) && is_array($phpData['versions'])) {
            foreach ($phpData['versions'] as $versionData) {
                $version = null;

                if (is_array($versionData) && isset($versionData['version'])) {
                    /** @var mixed $versionValue */
                    $versionValue = $versionData['version'];
                    if (is_string($versionValue)) {
                        $version = $versionValue;
                    } elseif (is_int($versionValue) || is_float($versionValue)) {
                        $version = (string) $versionValue;
                    }
                } elseif (is_string($versionData)) {
                    $version = $versionData;
                }

                if (null !== $version && '' !== $version) {
                    $key = "php{$version}-fpm";
                    $options[$key] = "PHP {$version} FPM";
                }
            }
        }

        //
        // Sites (Caddy access logs)

        if (isset($info['sites_config']) && is_array($info['sites_config'])) {
            foreach (array_keys($info['sites_config']) as $domain) {
                $options[(string) $domain] = "Site: {$domain}";
            }
        }

        //
        // Per-site resources (from inventory)

        foreach ($this->sites->findByServer($server->name) as $site) {
            // Cron scripts (one option per script)
            foreach ($site->crons as $cron) {
                $key = "cron:{$site->domain}/{$cron->script}";
                $options[$key] = "Cron: {$site->domain}/{$cron->script}";
            }

            // Supervisor programs
            foreach ($site->supervisors as $supervisor) {
                $key = "supervisor:{$site->domain}/{$supervisor->program}";
                $options[$key] = "Supervisor: {$site->domain}/{$supervisor->program}";
            }
        }

        return $options;
    }

    /**
     * Display logs for selected services.
     *
     * @param list<string> $services
     */
    protected function displayLogs(ServerDTO $server, array $services, int $lines): void
    {
        /** @var array<string, mixed> $info */
        $info = $server->info;

        /** @var list<string> $sites */
        $sites = isset($info['sites_config']) && is_array($info['sites_config'])
            ? array_map(strval(...), array_keys($info['sites_config']))
            : [];

        foreach ($services as $key) {
            //
            // Static sources

            if (isset(self::STATIC_SOURCES[$key])) {
                $source = self::STATIC_SOURCES[$key];
                $this->retrieveJournalLogs($server, $source['label'], $source['unit'] ?? null, $lines);

                continue;
            }

            //
            // Port-detected sources

            if (isset(self::PORT_SOURCES[$key])) {
                $source = self::PORT_SOURCES[$key];
                $label = $this->getServiceLabel($key);

                /** @var string $sourceType */
                $sourceType = $source['type'];

                if ('both' === $sourceType) {
                    /** @var string $unit */
                    $unit = $source['unit'];
                    /** @var string $path */
                    $path = $source['path'] ?? '';
                    $this->retrieveJournalLogs($server, "{$label} Service", $unit, $lines);
                    $this->retrieveFileLogs($server, "{$label} Error Log", $path, $lines);
                } else {
                    match ($sourceType) {
                        'journalctl' => $this->retrieveJournalLogs($server, $label, $source['unit'], $lines),
                        'file' => $this->retrieveFileLogs($server, $label, $source['path'] ?? '', $lines),
                        default => $this->warn("Unknown log type: {$sourceType}"),
                    };
                }

                continue;
            }

            //
            // PHP-FPM

            if (str_starts_with($key, 'php') && str_ends_with($key, '-fpm')) {
                $this->retrieveFileLogs($server, strtoupper($key), "/var/log/{$key}.log", $lines);

                continue;
            }

            //
            // Cron script logs

            if (str_starts_with($key, 'cron:')) {
                $parts = explode('/', substr($key, 5), 2);

                if (2 !== count($parts)) {
                    $this->warn("Invalid cron key format: {$key}");

                    continue;
                }

                [$domain, $script] = $parts;
                $scriptBase = pathinfo($script, PATHINFO_FILENAME);
                $this->retrieveFileLogs(
                    $server,
                    "Cron: {$domain}/{$script}",
                    "/var/log/cron/{$domain}-{$scriptBase}.log",
                    $lines
                );

                continue;
            }

            //
            // Supervisor program

            if (str_starts_with($key, 'supervisor:')) {
                $parts = explode('/', substr($key, 11), 2);

                if (2 !== count($parts)) {
                    $this->warn("Invalid supervisor key format: {$key}");

                    continue;
                }

                [$domain, $program] = $parts;
                $this->retrieveFileLogs(
                    $server,
                    "Supervisor: {$domain}/{$program}",
                    "/var/log/supervisor/{$domain}-{$program}.log",
                    $lines
                );

                continue;
            }

            //
            // Site access log

            if (in_array($key, $sites, true)) {
                $this->retrieveFileLogs($server, "Site: {$key}", "/var/log/caddy/{$key}-access.log", $lines);

                continue;
            }

            //
            // Unknown source (fallthrough warning)

            $this->warn("Unhandled log source: {$key}");
        }
    }

    // ----
    // Validation
    // ----

    /**
     * Validate services input.
     *
     * @param array<string, string> $allowedOptions
     */
    protected function validateServicesInput(mixed $value, array $allowedOptions): ?string
    {
        $services = is_string($value)
            ? array_filter(array_map(trim(...), explode(',', $value)))
            : $value;

        if (!is_array($services) || [] === $services) {
            return 'At least one service must be selected';
        }

        $invalid = array_diff($services, array_keys($allowedOptions));

        if ([] !== $invalid) {
            return sprintf("Invalid service(s): %s", implode(', ', $invalid));
        }

        return null;
    }
}
