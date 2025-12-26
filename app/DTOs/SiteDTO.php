<?php

declare(strict_types=1);

namespace DeployerPHP\DTOs;

readonly class SiteDTO
{
    /**
     * Create a SiteDTO containing the site's domain, repository, branch, associated server, PHP version, crons, and supervisors.
     *
     * @param string $domain The site's domain name (e.g. example.com).
     * @param ?string $repo   The repository URL for git sites (null if not yet configured).
     * @param ?string $branch The repository branch for git sites (null if not yet configured).
     * @param string $server Server name associated with the site.
     * @param string $phpVersion The PHP version configured for this site (e.g. "8.3").
     * @param array<int, CronDTO> $crons Array of cron jobs configured for this site.
     * @param array<int, SupervisorDTO> $supervisors Array of supervisor programs configured for this site.
     */
    public function __construct(
        public string $domain,
        public ?string $repo,
        public ?string $branch,
        public string $server,
        public string $phpVersion,
        /** @var array<int, CronDTO> */
        public array $crons = [],
        /** @var array<int, SupervisorDTO> */
        public array $supervisors = [],
    ) {
    }
}
