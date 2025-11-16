<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\DTOs\SiteDTO;
use Bigpixelrocket\DeployerPHP\Enums\Distribution;
use Bigpixelrocket\DeployerPHP\Repositories\ServerRepository;
use Bigpixelrocket\DeployerPHP\Services\IOService;
use Bigpixelrocket\DeployerPHP\Services\SSHService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable server things.
 *
 * Requires classes using this trait to have IOService, ServerRepository, SSHService, and SiteRepository properties.
 * Also requires PlaybooksTrait for serverInfo() method.
 *
 * @property IOService $io
 * @property ServerRepository $servers
 * @property SSHService $ssh
 * @property SiteRepository $sites
 */
trait ServersTrait
{
    use PlaybooksTrait;

    // ----
    // Helpers
    // ----

    //
    // Server info
    // ----

    /**
     * Get server information by executing server-info playbook.
     *
     * Automatically displays server info and validates that the server is running a supported distribution (Debian/Ubuntu)
     * and has sufficient permissions (root or sudo).
     *
     * @param ServerDTO $server Server to get information for
     * @return array<string, mixed>|int Returns parsed server info or failure code on failure
     */
    protected function serverInfo(ServerDTO $server): array|int
    {
        $info = $this->executePlaybook(
            $server,
            'server-info',
            'Retrieving server information...'
        );

        if (is_int($info)) {
            return $info;
        }

        // Display server information before validation
        $this->displayServerInfo($info);

        // Validate server distribution and permissions
        $distroResult = $this->validateServerDistribution($info);
        $permissionsResult = $this->validateServerPermissions($info);

        if (is_int($distroResult) || is_int($permissionsResult)) {
            return Command::FAILURE;
        }

        return $info;
    }

    /**
     * Validate that server is running a supported distribution.
     *
     * @param array<string, mixed> $info Server information array from server-info playbook
     * @return array<string, mixed>|int Returns validated server info or failure code
     */
    protected function validateServerDistribution(array $info): array|int
    {
        /** @var string $distro */
        $distro = $info['distro'] ?? 'unknown';
        $distribution = Distribution::tryFrom($distro);

        if ($distribution === null) {
            $this->nay("Unknown distribution: {$distro}");

            return Command::FAILURE;
        }

        $distroName = $distribution->displayName();

        if (!$distribution->isSupported()) {
            $this->nay("Unsupported distribution: {$distroName}. Only Debian and Ubuntu are supported.");

            return Command::FAILURE;
        }

        return $info;
    }

    /**
     * Validate that server has sufficient permissions (root or sudo).
     *
     * @param array<string, mixed> $info Server information array from server-info playbook
     * @return array<string, mixed>|int Returns validated server info or failure code
     */
    protected function validateServerPermissions(array $info): array|int
    {
        $permissions = $info['permissions'] ?? null;

        if (!is_string($permissions) || !in_array($permissions, ['root', 'sudo'])) {
            $this->nay('Server requires root or passwordless sudo permissions');
            $this->io->writeln([
                '',
                '<fg=yellow>To enable passwordless sudo on your server:</>',
                '  1. SSH to your server as root or with sudo access',
                '  2. Run: <fg=gray>echo "USERNAME ALL=(ALL) NOPASSWD:ALL" | sudo tee /etc/sudoers.d/USERNAME</>',
                '  3. Run: <fg=gray>sudo chmod 0440 /etc/sudoers.d/USERNAME</>',
                '  ^- Replace USERNAME with your actual username, e.g.: <fg=gray>echo "ubuntu ALL=(ALL) NOPASSWD:ALL" | sudo tee /etc/sudoers.d/ubuntu</>',
                '',
            ]);

            return Command::FAILURE;
        }

        return $info;
    }

    /**
     * Display formatted server information.
     *
     * @param array<string, mixed> $info
     */
    protected function displayServerInfo(array $info): void
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

        $this->io->displayDeets($deets);
        $this->io->writeln('');

        // Display hardware information if available
        if (isset($info['hardware']) && is_array($info['hardware'])) {
            $hardwareItems = [];

            if (isset($info['hardware']['cpu_cores'])) {
                /** @var int|string $cpuCores */
                $cpuCores = $info['hardware']['cpu_cores'];
                $coresText = $cpuCores === '1' || $cpuCores === 1 ? '1 core' : "{$cpuCores} cores";
                $hardwareItems[] = "CPU: {$coresText}";
            }

            if (isset($info['hardware']['ram_mb'])) {
                /** @var int|string $ramMb */
                $ramMb = $info['hardware']['ram_mb'];
                $ramGb = round((int) $ramMb / 1024, 1);
                $ramText = $ramGb >= 1 ? "{$ramGb} GB" : "{$ramMb} MB";
                $hardwareItems[] = "RAM: {$ramText}";
            }

            if (isset($info['hardware']['disk_type'])) {
                /** @var string $diskType */
                $diskType = $info['hardware']['disk_type'];
                $diskText = strtoupper($diskType);
                $hardwareItems[] = "Disk: {$diskText}";
            }

            if (count($hardwareItems) > 0) {
                $this->io->displayDeets(['Hardware' => $hardwareItems]);
                $this->io->writeln('');
            }
        }

        $services = [];

        // Add listening ports if any
        if (isset($info['ports']) && is_array($info['ports']) && count($info['ports']) > 0) {
            $portsList = [];
            foreach ($info['ports'] as $port => $process) {
                if (is_numeric($port) && is_string($process)) {
                    $portsList[] = "Port {$port}: {$process}";
                }
            }
            if (count($portsList) > 0) {
                $services = $portsList;
            }
        }

        $this->io->displayDeets(['Services' => $services]);
        $this->io->writeln('');

        // Display Caddy information if available
        if (isset($info['caddy']) && is_array($info['caddy']) && ($info['caddy']['available'] ?? false) === true) {
            $caddyItems = [];

            if (isset($info['caddy']['version']) && $info['caddy']['version'] !== 'unknown') {
                /** @var string $version */
                $version = $info['caddy']['version'];
                $caddyItems[] = 'Version: '.$version;
            }

            if (isset($info['caddy']['uptime_seconds'])) {
                /** @var int|string|float $rawUptime */
                $rawUptime = $info['caddy']['uptime_seconds'];
                /** @var int $uptimeSeconds */
                $uptimeSeconds = (int) $rawUptime;
                $caddyItems[] = 'Uptime: '.$this->formatUptime($uptimeSeconds);
            }

            if (isset($info['caddy']['total_requests'])) {
                /** @var int|string|float $rawTotalReq */
                $rawTotalReq = $info['caddy']['total_requests'];
                /** @var int $totalReq */
                $totalReq = (int) $rawTotalReq;
                $caddyItems[] = 'Total Requests: '.number_format($totalReq);
            }

            if (isset($info['caddy']['active_requests'])) {
                /** @var int|string $activeRequests */
                $activeRequests = $info['caddy']['active_requests'];
                $caddyItems[] = 'Active Requests: '.$activeRequests;
            }

            if (isset($info['caddy']['memory_mb']) && $info['caddy']['memory_mb'] !== '0') {
                /** @var string $memoryMb */
                $memoryMb = $info['caddy']['memory_mb'];
                $caddyItems[] = 'Memory: '.$memoryMb.' MB';
            }

            if (count($caddyItems) > 0) {
                $this->io->displayDeets(['Caddy' => $caddyItems]);
                $this->io->writeln('');
            }
        }

        // Display PHP versions if available
        if (isset($info['php']) && is_array($info['php'])) {
            $phpItems = [];

            if (isset($info['php']['versions']) && is_array($info['php']['versions']) && count($info['php']['versions']) > 0) {
                $versions = $info['php']['versions'];
                $defaultVersion = $info['php']['default'] ?? null;

                foreach ($versions as $versionData) {
                    // Handle both old format (string) and new format (array with version/extensions)
                    if (is_array($versionData) && isset($versionData['version'])) {
                        /** @var string|int|float */
                        $version = $versionData['version'];
                        $versionStr = (string) $version;
                        $extensions = $versionData['extensions'] ?? [];

                        // Build version line with extensions
                        $isDefault = false;
                        if ($defaultVersion !== null && (is_string($defaultVersion) || is_numeric($defaultVersion))) {
                            /** @var string|int|float $defaultVersion */
                            $isDefault = $versionStr === (string) $defaultVersion;
                        }

                        $versionLine = "PHP {$versionStr}";
                        if ($isDefault) {
                            $versionLine .= ' <fg=green>(default)</>';
                        }

                        // Add extensions if available
                        if (is_array($extensions) && count($extensions) > 0) {
                            $extCount = count($extensions);
                            $extList = implode(', ', $extensions);
                            $versionLine .= " with {$extCount} extensions: {$extList}";
                        }

                        $phpItems[] = $versionLine;
                    } elseif (is_string($versionData) || is_numeric($versionData)) {
                        // Fallback for old format (simple string/numeric version)
                        $versionStr = (string) $versionData;
                        if ($defaultVersion !== null && (is_string($defaultVersion) || is_numeric($defaultVersion))) {
                            /** @var string|int|float $defaultVersion */
                            $isDefault = $versionStr === (string) $defaultVersion;
                            if ($isDefault) {
                                $phpItems[] = "PHP {$versionStr} <fg=green>(default)</>";
                            } else {
                                $phpItems[] = "PHP {$versionStr}";
                            }
                        } else {
                            $phpItems[] = "PHP {$versionStr}";
                        }
                    }
                }
            }

            if (count($phpItems) > 0) {
                $this->io->displayDeets(['PHP' => $phpItems]);
                $this->io->writeln('');
            }
        }

        // Display PHP-FPM information if available (multiple versions)
        if (isset($info['php_fpm']) && is_array($info['php_fpm']) && count($info['php_fpm']) > 0) {
            foreach ($info['php_fpm'] as $version => $fpmData) {
                if (!is_array($fpmData) || !is_string($version)) {
                    continue;
                }

                $phpFpmItems = [];

                if (isset($fpmData['pool']) && $fpmData['pool'] !== 'unknown') {
                    /** @var string $pool */
                    $pool = $fpmData['pool'];
                    $phpFpmItems[] = 'Pool: '.$pool;
                }

                if (isset($fpmData['process_manager']) && $fpmData['process_manager'] !== 'unknown') {
                    /** @var string $processManager */
                    $processManager = $fpmData['process_manager'];
                    $phpFpmItems[] = 'Process Manager: '.$processManager;
                }

                if (isset($fpmData['active_processes'])) {
                    /** @var int|string $activeProcesses */
                    $activeProcesses = $fpmData['active_processes'];
                    $phpFpmItems[] = 'Active: '.$activeProcesses.' processes';
                }

                if (isset($fpmData['idle_processes'])) {
                    /** @var int|string $idleProcesses */
                    $idleProcesses = $fpmData['idle_processes'];
                    $phpFpmItems[] = 'Idle: '.$idleProcesses.' processes';
                }

                if (isset($fpmData['total_processes'])) {
                    /** @var int|string $totalProcesses */
                    $totalProcesses = $fpmData['total_processes'];
                    $phpFpmItems[] = 'Total: '.$totalProcesses.' processes';
                }

                if (isset($fpmData['listen_queue'])) {
                    /** @var int|string|float $rawQueue */
                    $rawQueue = $fpmData['listen_queue'];
                    /** @var int $queue */
                    $queue = (int) $rawQueue;
                    $queueDisplay = $queue > 0 ? "<fg=yellow>{$queue} waiting</>" : '0 waiting';
                    $phpFpmItems[] = 'Queue: '.$queueDisplay;
                }

                if (isset($fpmData['accepted_conn'])) {
                    /** @var int|string|float $rawAccepted */
                    $rawAccepted = $fpmData['accepted_conn'];
                    /** @var int $accepted */
                    $accepted = (int) $rawAccepted;
                    $phpFpmItems[] = 'Accepted: '.number_format($accepted);
                }

                if (isset($fpmData['max_children_reached'])) {
                    /** @var int|string|float $rawMaxChildren */
                    $rawMaxChildren = $fpmData['max_children_reached'];
                    /** @var int $maxChildren */
                    $maxChildren = (int) $rawMaxChildren;
                    if ($maxChildren > 0) {
                        $phpFpmItems[] = "<fg=yellow>Max Children Reached: {$maxChildren}</>";
                    }
                }

                if (isset($fpmData['slow_requests'])) {
                    /** @var int|string|float $rawSlowReqs */
                    $rawSlowReqs = $fpmData['slow_requests'];
                    /** @var int $slowReqsInt */
                    $slowReqsInt = (int) $rawSlowReqs;
                    if ($slowReqsInt > 0) {
                        $slowReqs = number_format($slowReqsInt);
                        $phpFpmItems[] = "<fg=yellow>Slow Requests: {$slowReqs}</>";
                    }
                }

                if (count($phpFpmItems) > 0) {
                    $this->io->displayDeets(["PHP-FPM {$version}" => $phpFpmItems]);
                    $this->io->writeln('');
                }
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

    //
    // UI
    // ----

    /**
     * Display a warning to add a server if no servers are available. Otherwise, return all servers.
     *
     * @param array<int, ServerDTO>|null $servers Optional pre-fetched servers; if null, fetches from repository
     * @return array<int, ServerDTO>|int Returns array of servers or Command::FAILURE if no servers available
     */
    protected function ensureServersAvailable(?array $servers = null): array|int
    {
        //
        // Get all servers

        $allServers = $servers ?? $this->servers->all();

        //
        // Check if no servers are available

        if (count($allServers) === 0) {
            $this->io->warning('No servers available');
            $this->io->writeln([
                '',
                'Run <fg=cyan>server:provision</> to provision your first server,',
                'or run <fg=cyan>server:add</> to add an existing server.',
                '',
            ]);

            return Command::FAILURE;
        }

        return $allServers;
    }

    /**
     * Select a server from inventory by name option or interactive prompt.
     *
     * @param array<int, ServerDTO>|null $servers Optional pre-fetched servers; if null, fetches from repository
     * @return ServerDTO|int Returns ServerDTO on success, or Command::FAILURE on error
     */
    protected function selectServer(?array $servers = null): ServerDTO|int
    {
        //
        // Get all servers

        if ($servers === null) {
            $servers = $this->ensureServersAvailable();

            if (is_int($servers)) {
                return Command::FAILURE;
            }
        }

        //
        // Extract server names and prompt for selection

        $serverNames = array_map(fn (ServerDTO $server) => $server->name, $servers);

        $name = (string) $this->io->getOptionOrPrompt(
            'server',
            fn () => $this->io->promptSelect(
                label: 'Select server:',
                options: $serverNames,
            )
        );

        //
        // Find server by name

        $server = $this->servers->findByName($name);

        if ($server === null) {
            $this->nay("Server '{$name}' not found in inventory");

            return Command::FAILURE;
        }

        return $server;
    }

    /**
     * Display server details including associated sites.
     */
    protected function displayServerDeets(ServerDTO $server): void
    {
        $deets = [
            'Name' => $server->name,
            'Host' => $server->host,
            'Port' => $server->port,
            'User' => $server->username,
            'Key' => $server->privateKeyPath ?? 'default (~/.ssh/id_ed25519 or ~/.ssh/id_rsa)',
        ];

        $sites = $this->sites->findByServer($server->name);

        if (count($sites) > 1) {
            $deets['Sites'] = array_map(fn (SiteDTO $site) => $site->domain, $sites);
        } elseif (count($sites) === 1) {
            $deets['Site'] = $sites[0]->domain;
        }

        $this->io->displayDeets($deets);
        $this->io->writeln('');
    }

    /**
     * Verify SSH connection to a server with proper error handling.
     *
     * Differentiates between fatal errors (authentication/key issues) and non-fatal
     * connection timeouts (expected for newly provisioned servers).
     *
     * @return int Returns Command::SUCCESS if verification succeeds or connection timeout (non-fatal), Command::FAILURE on fatal errors
     */
    protected function verifySSHConnection(ServerDTO $server): int
    {
        try {
            $this->io->promptSpin(
                callback: function () use ($server) {
                    $this->ssh->assertCanConnect($server);
                },
                message: 'Verifying SSH connection...'
            );

            $this->yay('SSH connection established');

            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            // Differentiate between connection issues (expected) and configuration errors (fatal)
            $message = $e->getMessage();
            if (str_contains($message, 'authentication') ||
                str_contains($message, 'key does not exist') ||
                str_contains($message, 'key permissions')) {
                $this->nay($e->getMessage());

                return Command::FAILURE;
            }

            // Connection timeout - expected for newly provisioned servers
            $this->io->warning('SSH is not responding');
            $this->io->writeln([
                '',
                '<fg=yellow>The server will be added to the inventory regardless. You can either:</>',
                '  • Wait a minute and run <fg=cyan>server:info --server=' . $server->name . '</> to check again',
                '  • Or run <fg=cyan>server:install --server=' . $server->name . '</> to install software when ready',
                '',
            ]);

            return Command::SUCCESS;
        }
    }

    //
    // Provider helpers
    // ----

    /**
     * Check if a server is provisioned on DigitalOcean.
     */
    protected function isDigitalOceanServer(ServerDTO $server): bool
    {
        return $server->provider === 'digitalocean' && $server->dropletId !== null;
    }

    // ----
    // Validation
    // ----

    /**
         * Validate server name format and uniqueness.
         *
         * @return string|null Error message if invalid, null if valid
         */
    protected function validateServerName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return 'Server name must be a string';
        }

        // Check if empty
        if (trim($name) === '') {
            return 'Server name cannot be empty';
        }

        // Validate format: alphanumeric, hyphens, underscores only
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            return 'Server name can only contain letters, numbers, hyphens, and underscores';
        }

        // Check uniqueness
        $existing = $this->servers->findByName($name);
        if ($existing !== null) {
            return "Server '{$name}' already exists in inventory";
        }

        return null;
    }

    /**
     * Validate host is a valid IP or domain and unique.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateServerHost(mixed $host): ?string
    {
        if (!is_string($host)) {
            return 'Host must be a string';
        }

        // Check format
        $isValidIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isValidDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if (!$isValidIp && !$isValidDomain) {
            return 'Must be a valid IP address or domain name (e.g., 192.168.1.100, example.com)';
        }

        // Check uniqueness
        $existing = $this->servers->findByHost($host);
        if ($existing !== null) {
            return "Host '{$host}' is already used by server '{$existing->name}'";
        }

        return null;
    }

    /**
     * Validate port is in valid range.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateServerPort(mixed $portString): ?string
    {
        if (!is_string($portString)) {
            return 'Port must be a string';
        }

        if (!ctype_digit($portString)) {
            return 'Port must be a number';
        }

        $port = (int) $portString;
        if ($port < 1 || $port > 65535) {
            return 'Port must be between 1 and 65535 (common SSH ports: 22, 2222, 22000)';
        }

        return null;
    }

}
