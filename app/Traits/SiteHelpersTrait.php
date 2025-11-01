<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\DTOs\SiteDTO;
use Bigpixelrocket\DeployerPHP\Repositories\ServerRepository;
use Bigpixelrocket\DeployerPHP\Repositories\SiteRepository;
use Bigpixelrocket\DeployerPHP\Services\IOService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable site-related helpers for commands.
 *
 * Requires classes using this trait to have IOService, ServerRepository, and SiteRepository properties.
 *
 * @property IOService $io
 * @property ServerRepository $servers
 * @property SiteRepository $sites
 */
trait SiteHelpersTrait
{
    /**
     * Display a warning to add a site if no sites are available. Otherwise, return all sites.
     *
     * @return array<int, SiteDTO>|int Returns array of sites or Command::SUCCESS if no sites available
     */
    protected function ensureSitesAvailable(): array|int
    {
        // Get all sites
        $allSites = $this->sites->all();

        // Check if no sites are available
        if (count($allSites) === 0) {
            $this->io->warning('No sites found in inventory');
            $this->io->writeln([
                '',
                'Use <fg=cyan>site:add</> to add a site',
                '',
            ]);

            return Command::SUCCESS;
        }

        return $allSites;
    }

    /**
     * Select a site from inventory by domain option or interactive prompt.
     *
     * @return SiteDTO|int Returns SiteDTO on success, or Command::SUCCESS if empty inventory, or Command::FAILURE if not found
     */
    protected function selectSite(string $optionName = 'site', string $promptLabel = 'Select site:'): SiteDTO|int
    {
        //
        // Get all sites

        $allSites = $this->ensureSitesAvailable();

        if (is_int($allSites)) {
            return $allSites;
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

            return Command::FAILURE;
        }

        return $site;
    }

    /**
     * Display site details.
     */
    protected function displaySiteDeets(SiteDTO $site): void
    {
        $details = ['Domain' => $site->domain];

        if ($site->isLocal()) {
            $details['Source'] = 'Local';
        } else {
            $details = [
                ...$details,
                'Source' => 'Git',
                'Repo' => $site->repo,
                'Branch' => $site->branch,
            ];
        }

        if (count($site->servers) > 1) {
            $details['Servers'] = $site->servers;
        } elseif (count($site->servers) === 1) {
            $details['Server'] = $site->servers[0];
        }

        $this->io->displayDeets($details);
        $this->io->writeln('');
    }
}
