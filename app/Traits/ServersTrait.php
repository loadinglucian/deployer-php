<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\DTOs\ServerDTO;
use Deployer\DTOs\SiteDTO;
use Deployer\Enums\Distribution;
use Deployer\Exceptions\ValidationException;
use Deployer\Repositories\ServerRepository;
use Deployer\Repositories\SiteRepository;
use Deployer\Services\IOService;
use Deployer\Services\SSHService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable server things.
 *
 * Requires classes using this trait to have IOService, ServerRepository, SSHService, and SiteRepository properties.
 * Also requires PlaybooksTrait for serverInfo() method.
 *
 * @mixin PlaybooksTrait
 *
 * @property IOService $io
 * @property ServerRepository $servers
 * @property SSHService $ssh
 * @property SiteRepository $sites
 */
trait ServersTrait
{
    // ----
    // Helpers
    // ----

    //
    // Data
    // ----

    /**
     * Extract port numbers from UFW rule strings.
     *
     * @param array<int, string> $rules UFW rules in format "port/proto" (e.g., "22/tcp")
     * @return array<int, int> List of port numbers
     */
    protected function extractPortsFromRules(array $rules): array
    {
        $ports = [];

        foreach ($rules as $rule) {
            if (preg_match('/^(\d+)/', $rule, $matches)) {
                $ports[] = (int) $matches[1];
            }
        }

        return $ports;
    }

    /**
     * Get configuration for a specific site from server info.
     *
     * @param array<string, mixed> $info Server information array
     * @param string $domain Site domain
     * @return array{php_version: string, www_mode: string, https_enabled: bool}|null Returns config or null if not found
     */
    protected function getSiteConfig(array $info, string $domain): ?array
    {
        if (! isset($info['sites_config']) || ! is_array($info['sites_config'])) {
            return null;
        }

        $config = $info['sites_config'][$domain] ?? null;

        if (! is_array($config)) {
            return null;
        }

        /** @var mixed $phpVer */
        $phpVer = $config['php_version'] ?? 'unknown';
        /** @var mixed $mode */
        $mode = $config['www_mode'] ?? 'unknown';

        return [
            'php_version' => is_scalar($phpVer) ? (string) $phpVer : 'unknown',
            'www_mode' => is_scalar($mode) ? (string) $mode : 'unknown',
            'https_enabled' => filter_var($config['https_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    //
    // UI
    // ----

    /**
     * Display firewall status and open ports.
     *
     * @param array<string, mixed> $info Server/detection information array
     */
    protected function displayFirewallDeets(array $info): void
    {
        /** @var bool $ufwInstalled */
        $ufwInstalled = filter_var($info['ufw_installed'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$ufwInstalled) {
            $this->displayDeets([
                'Firewall' => 'Not installed',
            ]);

            return;
        }

        /** @var bool $ufwActive */
        $ufwActive = filter_var($info['ufw_active'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (!$ufwActive) {
            $this->displayDeets([
                'Firewall' => 'Inactive',
            ]);

            return;
        }

        $this->displayDeets(['Firewall' => 'Active']);

        /** @var array<int, string> $ufwRules */
        $ufwRules = $info['ufw_rules'] ?? [];
        /** @var array<int, string> $ports */
        $ports = $info['ports'] ?? [];

        if ([] === $ufwRules) {
            $this->displayDeets(['Open Ports' => 'None']);
        } else {
            $openPorts = [];
            foreach ($this->extractPortsFromRules($ufwRules) as $port) {
                $process = $ports[$port] ?? 'unknown';
                $openPorts["Port {$port}"] = $process;
            }

            $this->displayDeets(['Open Ports' => $openPorts]);
        }
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

        $this->displayDeets($deets);
        $this->out('───');
    }

    /**
     * Display a warning to add a server if no servers are available. Otherwise, return all servers.
     *
     * @param  array<int, ServerDTO>|null  $servers  Optional pre-fetched servers; if null, fetches from repository
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
            $this->info('No servers found in your inventory:');
            $this->ul([
                'Run <fg=cyan>server:provision</> to provision a server',
                'Or run <fg=cyan>server:add</> to add an existing server',
            ]);

            return Command::FAILURE;
        }

        return $allServers;
    }

    /**
     * Resolve the server associated with a site, handling error output.
     */
    protected function getServerForSite(SiteDTO $site): ServerDTO|int
    {
        $server = $this->servers->findByName($site->server);

        if (null === $server) {
            $this->nay("Server '{$site->server}' not found in inventory");

            return Command::FAILURE;
        }

        return $server;
    }

    /**
     * Display server details, retrieve system inform and validate SSH connection and permissions.
     *
     * @param  ServerDTO  $server  The server to inspect
     * @return ServerDTO|int Returns updated ServerDTO with info or Command::FAILURE on error
     */
    protected function getServerInfo(ServerDTO $server): ServerDTO|int
    {
        $this->displayServerDeets($server);

        //
        // Get server info (also validates SSH connection)

        $info = $this->executePlaybookSilently(
            $server,
            'server-info',
            'Retrieving server information...',
            [],
        );

        if (is_int($info)) {
            return Command::FAILURE;
        }

        //
        // Validate distribution

        /** @var string $distro */
        $distro = $info['distro'] ?? 'unknown';
        $distribution = Distribution::tryFrom($distro);

        if (null === $distribution || ! $distribution->isSupported()) {
            $this->info('Deployer only supports Debian and Ubuntu.');

            return Command::FAILURE;
        }

        //
        // Validate permissions

        $permissions = $info['permissions'] ?? null;

        if (! is_string($permissions) || ! in_array($permissions, ['root', 'sudo'])) {
            $this->info('Deployer requires root or passwordless sudo permissions:');
            $this->ol([
                "SSH into your server as {$server->username}",
                "Run <|cyan>echo \"{$server->username} ALL=(ALL) NOPASSWD:ALL\" | sudo tee /etc/sudoers.d/{$server->username}</>",
                "Run <|cyan>sudo chmod 0440 /etc/sudoers.d/{$server->username}</>",
            ]);

            return Command::FAILURE;
        }

        return $server->withInfo($info);
    }

    /**
     * Select a server from inventory by name option or interactive prompt.
     *
     * @return ServerDTO|int Returns ServerDTO on success, or Command::FAILURE on error
     */
    protected function selectServerDeets(): ServerDTO|int
    {
        //
        // Get all servers

        $servers = $this->ensureServersAvailable();

        if (is_int($servers)) {
            return Command::FAILURE;
        }

        //
        // Extract server names and prompt for selection

        $serverNames = array_map(fn (ServerDTO $server) => $server->name, $servers);

        try {
            /** @var string $name */
            $name = $this->io->getValidatedOptionOrPrompt(
                'server',
                fn ($validate): int|string => $this->io->promptSelect(
                    label: 'Select server:',
                    options: $serverNames,
                    validate: $validate
                ),
                fn ($value) => $this->validateServerSelection($value)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        /** @var ServerDTO */
        $server = $this->servers->findByName($name);

        return $this->getServerInfo($server);
    }

    // ----
    // Validation
    // ----

    /**
     * Validate host is a valid IP or domain and unique.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateServerHost(mixed $host): ?string
    {
        if (! is_string($host)) {
            return 'Host must be a string';
        }

        // Check format
        $isValidIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isValidDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if (! $isValidIp && ! $isValidDomain) {
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
     * Validate server name format and uniqueness.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateServerName(mixed $name): ?string
    {
        if (! is_string($name)) {
            return 'Server name must be a string';
        }

        // Check if empty
        if (trim($name) === '') {
            return 'Server name cannot be empty';
        }

        // Validate format: alphanumeric, hyphens, underscores only
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            return 'Server name can only contain letters, numbers, hyphens, and underscores';
        }

        // Validate length
        if (strlen($name) > 64) {
            return 'Server name cannot exceed 64 characters';
        }

        // Check uniqueness
        $existing = $this->servers->findByName($name);
        if ($existing !== null) {
            return "Server '{$name}' already exists in inventory";
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
        if (! is_string($portString)) {
            return 'Port must be a string';
        }

        if (! ctype_digit($portString)) {
            return 'Port must be a number';
        }

        $port = (int) $portString;
        if ($port < 1 || $port > 65535) {
            return 'Port must be between 1 and 65535 (common SSH ports: 22, 2222, 22000)';
        }

        return null;
    }

    /**
     * Validate server selection exists in inventory.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateServerSelection(mixed $name): ?string
    {
        if (! is_string($name)) {
            return 'Server name must be a string';
        }

        if (null === $this->servers->findByName($name)) {
            return "Server '{$name}' not found in inventory";
        }

        return null;
    }

    /**
     * Validate username format.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateUsernameInput(mixed $username): ?string
    {
        if (! is_string($username)) {
            return 'Username must be a string';
        }

        if ('' === trim($username)) {
            return 'Username cannot be empty';
        }

        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $username)) {
            return 'Username must start with letter or underscore';
        }

        return null;
    }
}
