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
 * Also requires PlaybooksTrait for getServerInfo() method.
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
    protected function getServerInfo(ServerDTO $server): array|int
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
            $this->nay('Server requires root or sudo permissions');

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
