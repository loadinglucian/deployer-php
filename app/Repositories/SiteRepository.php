<?php

declare(strict_types=1);

namespace DeployerPHP\Repositories;

use DeployerPHP\Builders\SiteBuilder;
use DeployerPHP\DTOs\CronDTO;
use DeployerPHP\DTOs\SiteDTO;
use DeployerPHP\DTOs\SupervisorDTO;
use DeployerPHP\Services\InventoryService;

/**
 * Repository for site CRUD operations using inventory storage.
 *
 * Stores sites as an array of objects to handle any special characters in domain names.
 */
final class SiteRepository
{
    private const PREFIX = 'sites';

    private ?InventoryService $inventory = null;

    /** @var array<int, array<string, mixed>> */
    private array $sites = [];

    //
    // Public
    // ----

    /**
     * Configure the repository with an InventoryService and load site entries from storage.
     *
     * Loads the value stored under the repository's PREFIX key into the internal sites cache
     * ($this->sites). If the stored value is not an array, an empty array is persisted under
     * the PREFIX key and loaded into the cache.
     */
    public function loadInventory(InventoryService $inventory): void
    {
        $this->inventory = $inventory;

        $sites = $inventory->get(self::PREFIX);
        if (! is_array($sites)) {
            $sites = [];
            $inventory->set(self::PREFIX, $sites);
        }

        /** @var array<int, array<string, mixed>> $sites */
        $this->sites = $sites;
    }

    /**
     * Add a new site to the inventory storage ensuring the site's domain is unique.
     *
     * @param SiteDTO $site The site to store; its domain must not already exist in inventory.
     * @throws \RuntimeException If the inventory has not been loaded or a site with the same domain already exists.
     */
    public function create(SiteDTO $site): void
    {
        $this->assertInventoryLoaded();

        $existing = $this->findByDomain($site->domain);
        if (null !== $existing) {
            throw new \RuntimeException("Site '{$site->domain}' already exists");
        }

        $this->sites[] = $this->dehydrateSiteDTO($site);

        $this->inventory->set(self::PREFIX, $this->sites);
    }

    /**
     * Update an existing site in inventory storage.
     *
     * @param SiteDTO $site The site to update; must already exist by domain.
     * @throws \RuntimeException If the inventory has not been loaded or site does not exist.
     */
    public function update(SiteDTO $site): void
    {
        $this->assertInventoryLoaded();

        $found = false;
        foreach ($this->sites as $index => $siteData) {
            if (isset($siteData['domain']) && $siteData['domain'] === $site->domain) {
                $this->sites[$index] = $this->dehydrateSiteDTO($site);
                $found = true;
                break;
            }
        }

        if (! $found) {
            throw new \RuntimeException("Site '{$site->domain}' not found");
        }

        $this->inventory->set(self::PREFIX, $this->sites);
    }

    /**
     * Retrieve the site matching the given domain.
     *
     * @throws \RuntimeException If the inventory has not been loaded via loadInventory().
     * @return SiteDTO|null The SiteDTO for the matching domain, or `null` if no match is found.
     */
    public function findByDomain(string $domain): ?SiteDTO
    {
        $this->assertInventoryLoaded();

        foreach ($this->sites as $site) {
            if (isset($site['domain']) && $site['domain'] === $domain) {
                return $this->hydrateSiteDTO($site);
            }
        }

        return null;
    }

    /**
     * Retrieve all stored sites as SiteDTO objects.
     *
     * @return array<int, SiteDTO> An array of SiteDTO objects.
     */
    public function all(): array
    {
        $this->assertInventoryLoaded();

        $result = [];
        foreach ($this->sites as $site) {
            $result[] = $this->hydrateSiteDTO($site);
        }

        return $result;
    }

    /**
     * Retrieve all sites that belong to a specific server.
     *
     * @param string $serverName Server name to filter by
     * @return array<int, SiteDTO> Sites that include the server
     */
    public function findByServer(string $serverName): array
    {
        $this->assertInventoryLoaded();

        $filtered = [];
        foreach ($this->sites as $siteData) {
            $site = $this->hydrateSiteDTO($siteData);
            if ($site->server === $serverName) {
                $filtered[] = $site;
            }
        }

        return $filtered;
    }

    /**
     * Remove the site with the given domain from the stored inventory.
     *
     * If no site matches the domain, the inventory remains unchanged.
     *
     * @param string $domain The domain of the site to remove.
     */
    public function delete(string $domain): void
    {
        $this->assertInventoryLoaded();

        $filtered = [];
        foreach ($this->sites as $site) {
            if (isset($site['domain']) && $site['domain'] !== $domain) {
                $filtered[] = $site;
            }
        }

        $this->sites = $filtered;

        $this->inventory->set(self::PREFIX, $this->sites);
    }

    //
    // Cron CRUD
    // ----

    /**
     * Add a cron job to a site.
     *
     * @param string $domain The site's domain.
     * @param CronDTO $cron The cron job to add.
     * @throws \RuntimeException If the site does not exist or cron already exists.
     */
    public function addCron(string $domain, CronDTO $cron): void
    {
        $this->assertInventoryLoaded();

        $site = $this->findByDomain($domain);
        if (null === $site) {
            throw new \RuntimeException("Site '{$domain}' not found");
        }

        // Check for duplicate script
        foreach ($site->crons as $existing) {
            if ($existing->script === $cron->script) {
                throw new \RuntimeException("Cron '{$cron->script}' already exists for '{$domain}'");
            }
        }

        // Add cron to site
        $updatedSite = SiteBuilder::from($site)
            ->addCron($cron)
            ->build();

        $this->update($updatedSite);
    }

    /**
     * Delete a cron job from a site.
     *
     * @param string $domain The site's domain.
     * @param string $script The script name of the cron to delete.
     * @throws \RuntimeException If the site does not exist.
     */
    public function deleteCron(string $domain, string $script): void
    {
        $this->assertInventoryLoaded();

        $site = $this->findByDomain($domain);
        if (null === $site) {
            throw new \RuntimeException("Site '{$domain}' not found");
        }

        // Filter out the cron
        $crons = [];
        foreach ($site->crons as $existing) {
            if ($existing->script !== $script) {
                $crons[] = $existing;
            }
        }

        $updatedSite = SiteBuilder::from($site)
            ->crons($crons)
            ->build();

        $this->update($updatedSite);
    }

    //
    // Supervisor CRUD
    // ----

    /**
     * Add a supervisor program to a site.
     *
     * @param string $domain The site's domain.
     * @param SupervisorDTO $supervisor The supervisor program to add.
     * @throws \RuntimeException If the site does not exist or program already exists.
     */
    public function addSupervisor(string $domain, SupervisorDTO $supervisor): void
    {
        $this->assertInventoryLoaded();

        $site = $this->findByDomain($domain);
        if (null === $site) {
            throw new \RuntimeException("Site '{$domain}' not found");
        }

        // Check for duplicate program
        foreach ($site->supervisors as $existing) {
            if ($existing->program === $supervisor->program) {
                throw new \RuntimeException("Supervisor '{$supervisor->program}' already exists for '{$domain}'");
            }
        }

        // Add supervisor to site
        $updatedSite = SiteBuilder::from($site)
            ->addSupervisor($supervisor)
            ->build();

        $this->update($updatedSite);
    }

    /**
     * Delete a supervisor program from a site.
     *
     * @param string $domain The site's domain.
     * @param string $program The program name of the supervisor to delete.
     * @throws \RuntimeException If the site does not exist.
     */
    public function deleteSupervisor(string $domain, string $program): void
    {
        $this->assertInventoryLoaded();

        $site = $this->findByDomain($domain);
        if (null === $site) {
            throw new \RuntimeException("Site '{$domain}' not found");
        }

        // Filter out the supervisor
        $supervisors = [];
        foreach ($site->supervisors as $existing) {
            if ($existing->program !== $program) {
                $supervisors[] = $existing;
            }
        }

        $updatedSite = SiteBuilder::from($site)
            ->supervisors($supervisors)
            ->build();

        $this->update($updatedSite);
    }

    //
    // Private
    // ----

    /**
     * Asserts that the repository's inventory service has been loaded.
     *
     * @throws \RuntimeException If the inventory service has not been loaded.
     * @phpstan-assert !null $this->inventory
     */
    private function assertInventoryLoaded(): void
    {
        if (null === $this->inventory) {
            throw new \RuntimeException('Inventory not set. Call loadInventory() first.');
        }
    }

    /**
     * Serialize a SiteDTO into an associative array suitable for inventory storage.
     *
     * Only includes repo and branch if they are set. Only includes crons/supervisors if non-empty.
     *
     * @param SiteDTO $site The site DTO to serialize.
     * @return array<string, mixed> Associative array with keys `domain`, `server`, `php_version`, `www_mode`, `has_www`, and optional `repo`, `branch`, `crons`, `supervisors`.
     */
    private function dehydrateSiteDTO(SiteDTO $site): array
    {
        $data = [
            'domain' => $site->domain,
            'server' => $site->server,
        ];

        if (null !== $site->repo) {
            $data['repo'] = $site->repo;
        }

        if (null !== $site->branch) {
            $data['branch'] = $site->branch;
        }

        $data['php_version'] = $site->phpVersion;
        $data['www_mode'] = $site->wwwMode;
        $data['has_www'] = $site->hasWww;

        if ('public' !== $site->webRoot) {
            $data['web_root'] = $site->webRoot;
        }

        if ([] !== $site->crons) {
            $data['crons'] = array_map(
                $this->dehydrateCronDTO(...),
                $site->crons
            );
        }

        if ([] !== $site->supervisors) {
            $data['supervisors'] = array_map(
                $this->dehydrateSupervisorDTO(...),
                $site->supervisors
            );
        }

        return $data;
    }

    /**
     * Serialize a CronDTO into an associative array suitable for inventory storage.
     *
     * @param CronDTO $cron The cron DTO to serialize.
     * @return array<string, mixed> Associative array with keys `script`, `schedule`.
     */
    private function dehydrateCronDTO(CronDTO $cron): array
    {
        return [
            'script' => $cron->script,
            'schedule' => $cron->schedule,
        ];
    }

    /**
     * Create a SiteDTO from raw inventory data.
     *
     * @param array<string,mixed> $data Raw associative array from inventory.
     */
    private function hydrateSiteDTO(array $data): SiteDTO
    {
        return SiteBuilder::fromStorage($data)->build();
    }

    /**
     * Serialize a SupervisorDTO into an associative array suitable for inventory storage.
     *
     * @param SupervisorDTO $supervisor The supervisor DTO to serialize.
     * @return array<string, mixed> Associative array with keys `program`, `script`, `autostart`, `autorestart`, `stopwaitsecs`, `numprocs`.
     */
    private function dehydrateSupervisorDTO(SupervisorDTO $supervisor): array
    {
        return [
            'program' => $supervisor->program,
            'script' => $supervisor->script,
            'autostart' => $supervisor->autostart,
            'autorestart' => $supervisor->autorestart,
            'stopwaitsecs' => $supervisor->stopwaitsecs,
            'numprocs' => $supervisor->numprocs,
        ];
    }
}
