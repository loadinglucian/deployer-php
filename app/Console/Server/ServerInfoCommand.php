<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Server;

use DeployerPHP\Contracts\BaseCommand;
use DeployerPHP\Enums\Distribution;
use DeployerPHP\Traits\PlaybooksTrait;
use DeployerPHP\Traits\ServersTrait;
use DeployerPHP\Traits\ServicesTrait;
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
    }

    // ----
    // Execution
    // ----

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $this->h1('Server Information');

        //
        // Select server
        // ----

        $server = $this->selectServerDeets();

        if (is_int($server) || null === $server->info) {
            return Command::FAILURE;
        }

        $this->displayServerInfo($server->info);

        //
        // Show command replay
        // ----

        $this->commandReplay('server:info', [
            'server' => $server->name,
        ]);

        return Command::SUCCESS;
    }

    // ----
    // Helpers
    // ----

    /**
     * Display formatted server information.
     *
     * @param  array<string, mixed>  $info
     */
    private function displayServerInfo(array $info): void
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

        $this->displayDeets($deets);

        // Display hardware information if available
        if (isset($info['hardware']) && is_array($info['hardware'])) {
            $hardwareItems = [];

            if (isset($info['hardware']['cpu_cores'])) {
                /** @var int|string $cpuCores */
                $cpuCores = $info['hardware']['cpu_cores'];
                $coresText = $cpuCores === '1' || $cpuCores === 1 ? '1 core' : "{$cpuCores} cores";
                $hardwareItems['CPU'] = $coresText;
            }

            if (isset($info['hardware']['ram_mb'])) {
                /** @var int|string $ramMb */
                $ramMb = $info['hardware']['ram_mb'];
                $ramGb = round((int) $ramMb / 1024, 1);
                $ramText = $ramGb >= 1 ? "{$ramGb} GB" : "{$ramMb} MB";
                $hardwareItems['RAM'] = $ramText;
            }

            if (isset($info['hardware']['disk_type'])) {
                /** @var string $diskType */
                $diskType = $info['hardware']['disk_type'];
                $diskText = strtoupper($diskType);
                $hardwareItems['Disk'] = $diskText;
            }

            if (count($hardwareItems) > 0) {
                $this->displayDeets(['Hardware' => $hardwareItems]);
            }
        }

        $services = [];

        // Add listening ports if any
        if (isset($info['ports']) && is_array($info['ports']) && count($info['ports']) > 0) {
            $portsList = [];
            foreach ($info['ports'] as $port => $process) {
                if (is_numeric($port) && is_string($process)) {
                    $portsList["Port {$port}"] = $this->getServiceLabel($process);
                }
            }

            if (count($portsList) > 0) {
                $services = $portsList;
            }
        }

        if ([] === $services) {
            $this->displayDeets(['Services' => 'None detected']);
        } else {
            $this->displayDeets(['Services' => $services]);
        }

        $this->displayFirewallDeets($info);

        // Display Caddy information if available
        if (isset($info['caddy']) && is_array($info['caddy']) && ($info['caddy']['available'] ?? false) === true) {
            $caddyItems = [];

            if (isset($info['caddy']['version']) && $info['caddy']['version'] !== 'unknown') {
                /** @var string $version */
                $version = $info['caddy']['version'];
                $caddyItems['Version'] = $version;
            }

            if (isset($info['caddy']['uptime_seconds'])) {
                /** @var int|string|float $rawUptime */
                $rawUptime = $info['caddy']['uptime_seconds'];
                /** @var int $uptimeSeconds */
                $uptimeSeconds = (int) $rawUptime;
                $caddyItems['Uptime'] = $this->formatUptime($uptimeSeconds);
            }

            if (isset($info['caddy']['total_requests'])) {
                /** @var int|string|float $rawTotalReq */
                $rawTotalReq = $info['caddy']['total_requests'];
                /** @var int $totalReq */
                $totalReq = (int) $rawTotalReq;
                $caddyItems['Total Req'] = number_format($totalReq);
            }

            if (isset($info['caddy']['active_requests'])) {
                /** @var int|string $activeRequests */
                $activeRequests = $info['caddy']['active_requests'];
                $caddyItems['Active Req'] = $activeRequests;
            }

            if (isset($info['caddy']['memory_mb']) && $info['caddy']['memory_mb'] !== '0') {
                /** @var string $memoryMb */
                $memoryMb = $info['caddy']['memory_mb'];
                $caddyItems['Memory'] = $memoryMb.' MB';
            }

            if (count($caddyItems) > 0) {
                $this->displayDeets(['Caddy' => $caddyItems]);
            }
        }

        // Display PHP versions if available
        if (isset($info['php']) && is_array($info['php']) && isset($info['php']['versions']) && is_array($info['php']['versions'])) {
            /** @var array{versions: array<array{version: string, extensions: array<string>}>, default?: string} $phpInfo */
            $phpInfo = $info['php'];
            $versions = $phpInfo['versions'];

            if ([] !== $versions) {
                $phpItems = [];
                $defaultVersion = $phpInfo['default'] ?? '';

                foreach ($versions as $versionData) {
                    $version = $versionData['version'];
                    $extensions = $versionData['extensions'];

                    $versionLabel = "PHP {$version}";
                    if ($version === $defaultVersion) {
                        $versionLabel .= ' <fg=green>(default)</>';
                    }

                    $phpItems[$versionLabel] = [] !== $extensions
                        ? implode(', ', $extensions)
                        : 'no extensions';
                }

                $this->displayDeets(['PHP' => $phpItems]);
            }
        }

        // Display PHP-FPM information if available (multiple versions)
        if (isset($info['php_fpm']) && is_array($info['php_fpm']) && count($info['php_fpm']) > 0) {
            foreach ($info['php_fpm'] as $version => $fpmData) {
                if (! is_array($fpmData) || ! is_string($version)) {
                    continue;
                }

                $phpFpmItems = [];

                if (isset($fpmData['pool']) && $fpmData['pool'] !== 'unknown') {
                    /** @var string $pool */
                    $pool = $fpmData['pool'];
                    $phpFpmItems['Pool'] = $pool;
                }

                if (isset($fpmData['process_manager']) && $fpmData['process_manager'] !== 'unknown') {
                    /** @var string $processManager */
                    $processManager = $fpmData['process_manager'];
                    $phpFpmItems['Processes'] = $processManager;
                }

                if (isset($fpmData['active_processes'])) {
                    /** @var int|string $activeProcesses */
                    $activeProcesses = $fpmData['active_processes'];
                    $phpFpmItems['Active'] = $activeProcesses.' processes';
                }

                if (isset($fpmData['idle_processes'])) {
                    /** @var int|string $idleProcesses */
                    $idleProcesses = $fpmData['idle_processes'];
                    $phpFpmItems['Idle'] = $idleProcesses.' processes';
                }

                if (isset($fpmData['total_processes'])) {
                    /** @var int|string $totalProcesses */
                    $totalProcesses = $fpmData['total_processes'];
                    $phpFpmItems['Total'] = $totalProcesses.' processes';
                }

                if (isset($fpmData['listen_queue'])) {
                    /** @var int|string|float $rawQueue */
                    $rawQueue = $fpmData['listen_queue'];
                    /** @var int $queue */
                    $queue = (int) $rawQueue;
                    $phpFpmItems['Queue'] = $queue > 0 ? "<fg=yellow>{$queue} waiting</>" : '0 waiting';
                }

                if (isset($fpmData['accepted_conn'])) {
                    /** @var int|string|float $rawAccepted */
                    $rawAccepted = $fpmData['accepted_conn'];
                    /** @var int $accepted */
                    $accepted = (int) $rawAccepted;
                    $phpFpmItems['Accepted'] = number_format($accepted);
                }

                if (isset($fpmData['max_children_reached'])) {
                    /** @var int|string|float $rawMaxChildren */
                    $rawMaxChildren = $fpmData['max_children_reached'];
                    /** @var int $maxChildren */
                    $maxChildren = (int) $rawMaxChildren;
                    if ($maxChildren > 0) {
                        $phpFpmItems['<fg=yellow>Max Children Reached</>'] = $maxChildren;
                    }
                }

                if (isset($fpmData['slow_requests'])) {
                    /** @var int|string|float $rawSlowReqs */
                    $rawSlowReqs = $fpmData['slow_requests'];
                    /** @var int $slowReqsInt */
                    $slowReqsInt = (int) $rawSlowReqs;
                    if ($slowReqsInt > 0) {
                        $phpFpmItems['<fg=yellow>Slow Requests</>'] = number_format($slowReqsInt);
                    }
                }

                if (count($phpFpmItems) > 0) {
                    $this->displayDeets(["PHP-FPM {$version}" => $phpFpmItems]);
                }
            }
        }

        // Display Sites Configuration if available
        if (isset($info['sites_config']) && is_array($info['sites_config']) && count($info['sites_config']) > 0) {
            $sitesItems = [];
            foreach (array_keys($info['sites_config']) as $domain) {
                $config = $this->getSiteConfig($info, (string) $domain);

                if ($config === null) {
                    continue;
                }

                $php = $config['php_version'] === 'unknown' ? '?' : $config['php_version'];
                $url = $config['https_enabled'] ? "https://{$domain}" : "http://{$domain}";
                $color = $config['https_enabled'] ? 'green' : 'yellow';

                $sitesItems[(string) $domain] = "<fg={$color}>{$url}</> PHP {$php}";
            }

            if (count($sitesItems) > 0) {
                $this->displayDeets(['Sites' => $sitesItems]);
            }
        }
    }

    /**
     * Format uptime seconds into human-readable string.
     */
    private function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);

            return "{$minutes}m";
        }

        if ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);

            return "{$hours}h {$minutes}m";
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);

        return "{$days}d {$hours}h";
    }

}
