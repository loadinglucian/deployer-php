<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\DTOs\SiteDTO;
use Bigpixelrocket\DeployerPHP\Repositories\ServerRepository;
use Bigpixelrocket\DeployerPHP\Services\IOService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable server-related helpers for commands.
 *
 * Requires classes using this trait to have IOService and ServerRepository properties.
 *
 * @property IOService $io
 * @property ServerRepository $servers
 */
trait ServerHelpersTrait
{
    /**
     * Display a warning to add a server if no servers are available. Otherwise, return all servers.
     *
     * @return array<int, ServerDTO>|int Returns array of servers or Command::SUCCESS if no servers available
     */
    protected function ensureServersAvailable(): array|int
    {
        // Get all servers
        $allServers = $this->servers->all();

        // Check if no servers are available
        if (count($allServers) === 0) {
            $this->io->warning('No servers available');
            $this->io->writeln([
                '',
                'Run <fg=cyan>server:provision</> to provision your first server,',
                'or run <fg=cyan>server:add</> to add an existing server.',
                '',
            ]);

            return Command::SUCCESS;
        }

        return $allServers;
    }

    /**
     * Select a server from inventory by name option or interactive prompt.
     *
     * @return ServerDTO|int Returns ServerDTO on success, or Command::SUCCESS if empty inventory, or Command::FAILURE if not found
     */
    protected function selectServer(string $optionName = 'server', string $promptLabel = 'Select server:'): ServerDTO|int
    {
        //
        // Get all servers

        $allServers = $this->ensureServersAvailable();

        if (is_int($allServers)) {
            return $allServers;
        }

        //
        // Extract server names and prompt for selection

        $serverNames = array_map(fn (ServerDTO $server) => $server->name, $allServers);

        $name = (string) $this->io->getOptionOrPrompt(
            $optionName,
            fn () => $this->io->promptSelect(
                label: $promptLabel,
                options: $serverNames,
            )
        );

        //
        // Find server by name

        $server = $this->servers->findByName($name);

        if ($server === null) {
            $this->io->error("Server '{$name}' not found in inventory");

            return Command::FAILURE;
        }

        return $server;
    }

    /**
     * Display server details including optional sites.
     *
     * @param array<int, \Bigpixelrocket\DeployerPHP\DTOs\SiteDTO> $sites
     */
    protected function displayServerDeets(ServerDTO $server, array $sites = []): void
    {
        $deets = [
            'Name' => $server->name,
            'Host' => $server->host,
            'Port' => $server->port,
            'User' => $server->username,
            'Key' => $server->privateKeyPath ?? 'default (~/.ssh/id_ed25519 or ~/.ssh/id_rsa)',
        ];

        if (count($sites) > 1) {
            $deets['Sites'] = array_map(fn (SiteDTO $site) => $site->domain, $sites);
        } elseif (count($sites) === 1) {
            $deets['Site'] = $sites[0]->domain;
        }

        $this->io->displayDeets($deets);
        $this->io->writeln('');
    }

    /**
     * Check if a server is provisioned on DigitalOcean.
     */
    protected function isDigitalOceanServer(ServerDTO $server): bool
    {
        return $server->provider === 'digitalocean' && $server->dropletId !== null;
    }
}
