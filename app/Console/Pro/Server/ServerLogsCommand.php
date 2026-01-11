<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Pro\Server;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\DTOs\ServerDTO;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Traits\LogsTrait;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\ServicesTrait;
use DeployerPHP\Traits\SitesTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'pro:server:logs|server:logs',
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
        'nginx' => ['type' => 'journalctl', 'unit' => 'nginx'],
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
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Filter logs to a specific site');
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
        // Validate site filter
        // ----

        /** @var string|null $siteFilter */
        $siteFilter = $this->io->getOptionValue('site');

        if (null !== $siteFilter) {
            $error = $this->validateSiteFilter($siteFilter, $server);

            if (null !== $error) {
                $this->nay($error);

                return Command::FAILURE;
            }
        }

        //
        // Build log options
        // ----

        $options = $this->buildLogOptions($server, $siteFilter);

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
        // Normalize and expand services input
        // ----

        /** @var list<string> $serviceKeys */
        $serviceKeys = is_string($services)
            ? array_filter(array_map(trim(...), explode(',', $services)))
            : $services;

        $serviceKeys = $this->expandGroupSelections($serviceKeys, $server);

        //
        // Display logs
        // ----

        $this->displayLogs($server, $serviceKeys, (int) $lines);

        //
        // Command replay
        // ----

        $replayOptions = [
            'server' => $server->name,
            'lines' => $lines,
            'service' => implode(',', $serviceKeys),
        ];

        if (null !== $siteFilter) {
            $replayOptions['site'] = $siteFilter;
        }

        $this->commandReplay('server:logs', $replayOptions);

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
    protected function buildLogOptions(ServerDTO $server, ?string $siteFilter = null): array
    {
        /** @var array<string, mixed> $info */
        $info = $server->info;
        $options = [];

        // When filtering by site, only show that site's logs
        if (null !== $siteFilter) {
            return $this->buildSiteFilteredOptions($server, $siteFilter);
        }

        // Static sources (always available)
        foreach (self::STATIC_SOURCES as $key => $source) {
            $options[$key] = $source['label'];
        }

        // Port-detected services
        $this->addPortDetectedOptions($info, $options);

        // PHP-FPM versions
        $this->addPhpFpmOptions($info, $options);

        // Sites (Nginx access logs)
        $sites = $this->addSiteOptions($info, $options);

        // Per-site resources (from inventory)
        [$hasCrons, $hasSupervisors] = $this->addSiteResourceOptions($server, $options);

        // Group shortcuts
        $this->addGroupOptions($options, $sites, $hasCrons, $hasSupervisors);

        return $options;
    }

    /**
     * Build options filtered to a single site.
     *
     * @return array<string, string>
     */
    private function buildSiteFilteredOptions(ServerDTO $server, string $siteFilter): array
    {
        $options = [];

        // Site access log
        $options[$siteFilter] = "Site: {$siteFilter}";

        // Site's crons and supervisors
        $site = $this->sites->findByDomain($siteFilter);

        if (null !== $site) {
            foreach ($site->crons as $cron) {
                $key = "cron:{$site->domain}/{$cron->script}";
                $options[$key] = "Cron: {$site->domain}/{$cron->script}";
            }

            foreach ($site->supervisors as $supervisor) {
                $key = "supervisor:{$site->domain}/{$supervisor->program}";
                $options[$key] = "Supervisor: {$site->domain}/{$supervisor->program}";
            }
        }

        return $options;
    }

    /**
     * Add group selection shortcuts.
     *
     * @param array<string, string> $options
     * @param list<string> $sites
     */
    private function addGroupOptions(array &$options, array $sites, bool $hasCrons, bool $hasSupervisors): void
    {
        if ([] !== $sites) {
            $options['all-sites'] = 'All site access logs';
        }

        if ($hasCrons) {
            $options['all-crons'] = 'Cron service + all script logs';
        }

        if ($hasSupervisors) {
            $options['all-supervisors'] = 'Supervisor service + all program logs';
        }
    }

    /**
     * Add port-detected service options.
     *
     * @param array<string, mixed> $info
     * @param array<string, string> $options
     */
    private function addPortDetectedOptions(array $info, array &$options): void
    {
        /** @var array<int, string> $ports */
        $ports = $info['ports'] ?? [];

        foreach (array_unique(array_values($ports)) as $process) {
            $key = strtolower((string) $process);

            if (isset(self::PORT_SOURCES[$key])) {
                $options[$key] = $this->getServiceLabel($key);
            }
        }
    }

    /**
     * Add PHP-FPM version options.
     *
     * @param array<string, mixed> $info
     * @param array<string, string> $options
     */
    private function addPhpFpmOptions(array $info, array &$options): void
    {
        /** @var array<mixed, mixed> $phpData */
        $phpData = is_array($info['php'] ?? null) ? $info['php'] : [];

        if (!isset($phpData['versions']) || !is_array($phpData['versions'])) {
            return;
        }

        foreach ($phpData['versions'] as $versionData) {
            $version = $this->extractPhpVersion($versionData);

            if (null !== $version) {
                $key = "php{$version}-fpm";
                $options[$key] = "PHP {$version} FPM";
            }
        }
    }

    /**
     * Extract PHP version string from version data.
     */
    private function extractPhpVersion(mixed $versionData): ?string
    {
        if (is_string($versionData) && '' !== $versionData) {
            return $versionData;
        }

        if (!is_array($versionData) || !isset($versionData['version'])) {
            return null;
        }

        /** @var mixed $versionValue */
        $versionValue = $versionData['version'];

        if (is_string($versionValue) && '' !== $versionValue) {
            return $versionValue;
        }

        if (is_int($versionValue) || is_float($versionValue)) {
            return (string) $versionValue;
        }

        return null;
    }

    /**
     * Add site access log options.
     *
     * @param array<string, mixed> $info
     * @param array<string, string> $options
     * @return list<string> List of site domains
     */
    private function addSiteOptions(array $info, array &$options): array
    {
        if (!isset($info['sites_config']) || !is_array($info['sites_config'])) {
            return [];
        }

        $sites = [];

        foreach (array_keys($info['sites_config']) as $domain) {
            $sites[] = (string) $domain;
            $options[(string) $domain] = "Site: {$domain}";
        }

        return $sites;
    }

    /**
     * Add per-site resource options (crons, supervisors).
     *
     * @param array<string, string> $options
     * @return array{bool, bool} [hasCrons, hasSupervisors]
     */
    private function addSiteResourceOptions(ServerDTO $server, array &$options): array
    {
        $hasCrons = false;
        $hasSupervisors = false;

        foreach ($this->sites->findByServer($server->name) as $site) {
            foreach ($site->crons as $cron) {
                $key = "cron:{$site->domain}/{$cron->script}";
                $options[$key] = "Cron: {$site->domain}/{$cron->script}";
                $hasCrons = true;
            }

            foreach ($site->supervisors as $supervisor) {
                $key = "supervisor:{$site->domain}/{$supervisor->program}";
                $options[$key] = "Supervisor: {$site->domain}/{$supervisor->program}";
                $hasSupervisors = true;
            }
        }

        return [$hasCrons, $hasSupervisors];
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
            $this->displayLogForKey($server, $key, $sites, $lines);
        }
    }

    /**
     * Display log for a single service key.
     *
     * @param list<string> $sites
     */
    private function displayLogForKey(ServerDTO $server, string $key, array $sites, int $lines): void
    {
        // Static sources
        if ($this->displayStaticLog($server, $key, $lines)) {
            return;
        }

        // Port-detected sources
        if ($this->displayPortLog($server, $key, $lines)) {
            return;
        }

        // PHP-FPM
        if (str_starts_with($key, 'php') && str_ends_with($key, '-fpm')) {
            $this->retrieveFileLogs($server, strtoupper($key), "/var/log/{$key}.log", $lines);

            return;
        }

        // Cron script logs
        if ($this->displayCronLog($server, $key, $lines)) {
            return;
        }

        // Supervisor program
        if ($this->displaySupervisorLog($server, $key, $lines)) {
            return;
        }

        // Site access log
        if (in_array($key, $sites, true)) {
            $this->retrieveFileLogs($server, "Site: {$key}", "/var/log/nginx/{$key}-access.log", $lines);

            return;
        }

        $this->warn("Unhandled log source: {$key}");
    }

    /**
     * Display static source log if key matches.
     */
    private function displayStaticLog(ServerDTO $server, string $key, int $lines): bool
    {
        if (!isset(self::STATIC_SOURCES[$key])) {
            return false;
        }

        $source = self::STATIC_SOURCES[$key];
        $this->retrieveJournalLogs($server, $source['label'], $source['unit'] ?? null, $lines);

        return true;
    }

    /**
     * Display port-detected source log if key matches.
     */
    private function displayPortLog(ServerDTO $server, string $key, int $lines): bool
    {
        if (!isset(self::PORT_SOURCES[$key])) {
            return false;
        }

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

        return true;
    }

    /**
     * Display cron log if key matches.
     */
    private function displayCronLog(ServerDTO $server, string $key, int $lines): bool
    {
        if (!str_starts_with($key, 'cron:')) {
            return false;
        }

        $parts = explode('/', substr($key, 5), 2);

        if (2 !== count($parts)) {
            $this->warn("Invalid cron key format: {$key}");

            return true;
        }

        [$domain, $script] = $parts;
        $scriptBase = pathinfo($script, PATHINFO_FILENAME);
        $this->retrieveFileLogs(
            $server,
            "Cron: {$domain}/{$script}",
            "/var/log/cron/{$domain}-{$scriptBase}.log",
            $lines
        );

        return true;
    }

    /**
     * Display supervisor log if key matches.
     */
    private function displaySupervisorLog(ServerDTO $server, string $key, int $lines): bool
    {
        if (!str_starts_with($key, 'supervisor:')) {
            return false;
        }

        $parts = explode('/', substr($key, 11), 2);

        if (2 !== count($parts)) {
            $this->warn("Invalid supervisor key format: {$key}");

            return true;
        }

        [$domain, $program] = $parts;
        $this->retrieveFileLogs(
            $server,
            "Supervisor: {$domain}/{$program}",
            "/var/log/supervisor/{$domain}-{$program}.log",
            $lines
        );

        return true;
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

    /**
     * Validate site filter exists and belongs to the server.
     */
    protected function validateSiteFilter(string $site, ServerDTO $server): ?string
    {
        $siteDto = $this->sites->findByDomain($site);

        if (null === $siteDto) {
            return "Site '{$site}' not found in inventory";
        }

        if ($siteDto->server !== $server->name) {
            return "Site '{$site}' is not on server '{$server->name}'";
        }

        return null;
    }

    /**
     * Expand group selections into individual log keys.
     *
     * @param list<string> $selected
     * @return list<string>
     */
    protected function expandGroupSelections(array $selected, ServerDTO $server): array
    {
        /** @var array<string, mixed> $info */
        $info = $server->info ?? [];
        $expanded = [];

        // Collect site domains
        $sites = isset($info['sites_config']) && is_array($info['sites_config'])
            ? array_map(strval(...), array_keys($info['sites_config']))
            : [];

        // Collect cron and supervisor keys
        $cronKeys = [];
        $supervisorKeys = [];

        foreach ($this->sites->findByServer($server->name) as $site) {
            foreach ($site->crons as $cron) {
                $cronKeys[] = "cron:{$site->domain}/{$cron->script}";
            }

            foreach ($site->supervisors as $supervisor) {
                $supervisorKeys[] = "supervisor:{$site->domain}/{$supervisor->program}";
            }
        }

        // Expand group selections
        foreach ($selected as $key) {
            if ('all-sites' === $key) {
                $expanded = array_merge($expanded, $sites);
            } elseif ('all-crons' === $key) {
                $expanded[] = 'cron';
                $expanded = array_merge($expanded, $cronKeys);
            } elseif ('all-supervisors' === $key) {
                $expanded[] = 'supervisor';
                $expanded = array_merge($expanded, $supervisorKeys);
            } else {
                $expanded[] = $key;
            }
        }

        return array_values(array_unique($expanded));
    }
}
