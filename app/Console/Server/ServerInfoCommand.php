<?php

declare(strict_types=1);

namespace DeployerPHP\Console\Server;

use DeployerPHP\Contracts\BaseCommand;
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

    private const LOAD_WARNING_RATIO = 1.0;
    private const LOAD_CRITICAL_RATIO = 1.5;
    private const MEMORY_WARNING_PERCENT = 85;
    private const MEMORY_CRITICAL_PERCENT = 92;

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

        $this->commandReplay([
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
        $distroName = 'ubuntu' === $distroSlug ? 'Ubuntu' : 'Unsupported';

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
            $cpuCoreCount = $this->parseIntValue($info['hardware']['cpu_cores'] ?? null);
            $ramTotalMb = $this->parseIntValue($info['hardware']['ram_mb'] ?? null);

            if (isset($info['hardware']['cpu_cores'])) {
                /** @var int|string $cpuCores */
                $cpuCores = $info['hardware']['cpu_cores'];
                $coresText = $cpuCores === '1' || $cpuCores === 1 ? '1 core' : "{$cpuCores} cores";
                $hardwareItems['CPU'] = $coresText;
            }

            if (null !== $ramTotalMb) {
                $ramGb = round($ramTotalMb / 1024, 1);
                $ramText = $ramGb >= 1 ? "{$ramGb} GB" : "{$ramTotalMb} MB";
                $hardwareItems['RAM'] = $ramText;
            }

            $load1m = $this->parseFloatValue($info['hardware']['load_1m'] ?? null);
            $load5m = $this->parseFloatValue($info['hardware']['load_5m'] ?? null);
            $load15m = $this->parseFloatValue($info['hardware']['load_15m'] ?? null);

            if (null !== $load1m && null !== $load5m && null !== $load15m) {
                $loadText = sprintf('%.2f / %.2f / %.2f', $load1m, $load5m, $load15m);
                $loadRatio = null;

                if (null !== $cpuCoreCount && $cpuCoreCount > 0) {
                    $loadRatio = $load1m / $cpuCoreCount;
                    $loadText .= sprintf(' (1m/core: %.2f)', $loadRatio);
                }

                if (null !== $loadRatio) {
                    if ($loadRatio >= self::LOAD_CRITICAL_RATIO) {
                        $loadText = "<fg=red>{$loadText}</>";
                    } elseif ($loadRatio >= self::LOAD_WARNING_RATIO) {
                        $loadText = "<fg=yellow>{$loadText}</>";
                    }
                }

                $hardwareItems['Load'] = $loadText;
            }

            $memoryUsedMb = $this->parseIntValue($info['hardware']['memory_used_mb'] ?? null);
            $memoryUsedPercent = $this->parseIntValue($info['hardware']['memory_used_percent'] ?? null);
            if (null !== $memoryUsedMb && null !== $ramTotalMb && $ramTotalMb > 0 && $memoryUsedMb >= 0) {
                if (null === $memoryUsedPercent) {
                    $memoryUsedPercent = (int) round(($memoryUsedMb * 100) / $ramTotalMb);
                }
                $memoryUsedPercent = max(0, min(100, $memoryUsedPercent));

                $memoryUsedText = sprintf(
                    '%s / %s (%d%%)',
                    $this->formatMegabytesHuman($memoryUsedMb),
                    $this->formatMegabytesHuman($ramTotalMb),
                    $memoryUsedPercent
                );

                if ($memoryUsedPercent >= self::MEMORY_CRITICAL_PERCENT) {
                    $memoryUsedText = "<fg=red>{$memoryUsedText}</>";
                } elseif ($memoryUsedPercent >= self::MEMORY_WARNING_PERCENT) {
                    $memoryUsedText = "<fg=yellow>{$memoryUsedText}</>";
                }

                $hardwareItems['Memory Used'] = $memoryUsedText;
            }

            if (isset($info['hardware']['disk_type'])) {
                /** @var string $diskType */
                $diskType = $info['hardware']['disk_type'];
                $diskText = strtoupper($diskType);
                $hardwareItems['Disk Type'] = $diskText;
            }

            $diskTotalBytes = $this->parseIntValue($info['hardware']['disk_total_bytes'] ?? null);
            $diskUsedBytes = $this->parseIntValue($info['hardware']['disk_used_bytes'] ?? null);
            $diskFreeBytes = $this->parseIntValue($info['hardware']['disk_free_bytes'] ?? null);
            $diskFreePercent = $this->parseIntValue($info['hardware']['disk_free_percent'] ?? null);

            if (null !== $diskTotalBytes && null !== $diskUsedBytes && null !== $diskFreeBytes
                && $diskTotalBytes > 0 && $diskUsedBytes >= 0 && $diskFreeBytes >= 0) {
                $hardwareItems['Disk Capacity'] = $this->formatBytesHuman($diskTotalBytes);
                $hardwareItems['Disk Used'] = $this->formatBytesHuman($diskUsedBytes);

                $diskFreeText = $this->formatBytesHuman($diskFreeBytes);
                if (null !== $diskFreePercent && $diskFreePercent >= 0 && $diskFreePercent <= 100) {
                    $diskFreeText .= " ({$diskFreePercent}% free)";
                }
                $hardwareItems['Disk Free'] = $diskFreeText;
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

        // Display Nginx information if available
        if (isset($info['nginx']) && is_array($info['nginx']) && true === ($info['nginx']['available'] ?? false)) {
            $nginxItems = [];

            if (isset($info['nginx']['version']) && 'unknown' !== $info['nginx']['version']) {
                /** @var string $version */
                $version = $info['nginx']['version'];
                $nginxItems['Version'] = $version;
            }

            if (isset($info['nginx']['active_connections'])) {
                /** @var int|string $activeConns */
                $activeConns = $info['nginx']['active_connections'];
                $nginxItems['Active Conn'] = $activeConns;
            }

            if (isset($info['nginx']['requests'])) {
                /** @var int|string|float $rawRequests */
                $rawRequests = $info['nginx']['requests'];
                /** @var int $requests */
                $requests = (int) $rawRequests;
                $nginxItems['Requests'] = number_format($requests);
            }

            if (count($nginxItems) > 0) {
                $this->displayDeets(['Nginx' => $nginxItems]);
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

        $this->out('───');
    }

    /**
     * Parse a scalar value to int when numeric.
     */
    private function parseIntValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if ((is_string($value) || is_float($value)) && is_numeric((string) $value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Parse a scalar value to float when numeric.
     */
    private function parseFloatValue(mixed $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if ((is_int($value) || is_string($value)) && is_numeric((string) $value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Format bytes as a human-readable string with one decimal place.
     */
    private function formatBytesHuman(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $unitIndex = -1;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return sprintf('%.1f %s', $value, $units[$unitIndex]);
    }

    /**
     * Format megabytes as a human-readable string.
     */
    private function formatMegabytesHuman(int $megabytes): string
    {
        if ($megabytes < 1024) {
            return "{$megabytes} MB";
        }

        $units = ['GB', 'TB', 'PB'];
        $value = (float) $megabytes / 1024;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return sprintf('%.1f %s', $value, $units[$unitIndex]);
    }
}
