<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\DTOs\SiteDTO;
use Bigpixelrocket\DeployerPHP\Repositories\ServerRepository;
use Bigpixelrocket\DeployerPHP\Repositories\SiteRepository;
use Bigpixelrocket\DeployerPHP\Services\IOService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable site-related helpers for commands.
 *
 * Requires the using class to extend BaseCommand and have:
 * - protected ServerRepository $servers
 * - protected SiteRepository $sites
 * - protected IOService $io
 */
trait SiteHelpersTrait
{
    /**
     * Select a site from inventory by domain option or interactive prompt.
     *
     * @return array{site: SiteDTO|null, exit_code: int} Site DTO and exit code (SUCCESS if empty inventory, FAILURE if not found)
     */
    protected function selectSite(string $optionName = 'site', string $promptLabel = 'Select site:'): array
    {
        //
        // Get all sites

        $allSites = $this->sites->all();
        if (count($allSites) === 0) {
            $this->io->warning('No sites found in inventory');
            $this->io->writeln([
                '',
                'Use <fg=cyan>site:add</> to add a site',
                '',
            ]);

            return ['site' => null, 'exit_code' => Command::SUCCESS];
        }

        //
        // Extract site domains and prompt for selection

        $siteDomains = array_map(fn (SiteDTO $site) => $site->domain, $allSites);

        $domain = (string) $this->io->getOptionOrPrompt(
            $optionName,
            fn () => $this->io->promptSelect(
                label: $promptLabel,
                options: $siteDomains,
            )
        );

        //
        // Find site by domain

        $site = $this->sites->findByDomain($domain);

        if ($site === null) {
            $this->io->error("Site '{$domain}' not found in inventory");

            return ['site' => null, 'exit_code' => Command::FAILURE];
        }

        return ['site' => $site, 'exit_code' => Command::SUCCESS];
    }

    /**
     * Multi-select servers from inventory.
     *
     * Supports both CLI option (comma-separated server names) and interactive multiselect prompt.
     *
     * @param string $optionName Option name to check for pre-provided values
     * @return array<int, string> Selected server names
     */
    protected function selectServers(string $optionName = 'servers'): array
    {
        //
        // Get all servers and extract names

        $allServers = $this->servers->all();
        $serverNames = array_map(fn (ServerDTO $server): string => $server->name, $allServers);

        //
        // Get servers via option or prompt

        /** @var string|array<int, string> $serversInput */
        $serversInput = $this->io->getOptionOrPrompt(
            $optionName,
            fn (): array => $this->io->promptMultiselect(
                label: 'Select servers:',
                options: $serverNames,
                required: true
            )
        );

        //
        // Parse input into array of server names

        if (is_string($serversInput)) {
            // Parse comma-separated server names from CLI option
            $selectedServers = array_map(trim(...), explode(',', $serversInput));

            // Validate servers exist
            foreach ($selectedServers as $serverName) {
                if ($this->servers->findByName($serverName) === null) {
                    throw new \RuntimeException("Server '{$serverName}' not found in inventory");
                }
            }
        } else {
            // Already an array from interactive prompt
            $selectedServers = $serversInput;
        }

        // Ensure array values are strings with sequential integer keys
        return array_values(array_filter(array_map(strval(...), $selectedServers)));
    }

    /**
     * Display site details.
     */
    protected function displaySiteDeets(SiteDTO $site): void
    {
        $lines = ["  Domain:  <fg=gray>{$site->domain}</>"];

        if ($site->isLocal()) {
            $lines[] = "  Type:    <fg=gray>Local</>";
        } else {
            $lines[] = "  Type:    <fg=gray>Git</>";
            $lines[] = "  Repo:    <fg=gray>{$site->repo}</>";
            $lines[] = "  Branch:  <fg=gray>{$site->branch}</>";
        }

        $lines[] = "  Servers: <fg=gray>".implode(', ', $site->servers).'</>';
        $lines[] = ' ';

        $this->io->writeln($lines);
    }
}
