<?php

declare(strict_types=1);

namespace DeployerPHP\DTOs;

use DeployerPHP\Enums\WwwMode;

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
     * @param string $wwwMode The configured WWW mode (`redirect-to-root`, `redirect-to-www`, `none`, or `unknown`).
     * @param bool $hasWww Whether the site is expected to have a `www` alias.
     * @param string $webRoot The public web directory relative to current/ (e.g. "public", "web", or "" for root).
     * @param array<int, CronDTO> $crons Array of cron jobs configured for this site.
     * @param array<int, SupervisorDTO> $supervisors Array of supervisor programs configured for this site.
     */
    public function __construct(
        public string $domain,
        public ?string $repo,
        public ?string $branch,
        public string $server,
        public string $phpVersion,
        public string $wwwMode = WwwMode::UNKNOWN->value,
        public bool $hasWww = false,
        public string $webRoot = 'public',
        /** @var array<int, CronDTO> */
        public array $crons = [],
        /** @var array<int, SupervisorDTO> */
        public array $supervisors = [],
    ) {
    }
}
