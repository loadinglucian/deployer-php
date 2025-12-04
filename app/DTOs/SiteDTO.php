<?php

declare(strict_types=1);

namespace Deployer\DTOs;

readonly class SiteDTO
{
    /**
     * Create a SiteDTO containing the site's domain, repository, branch, and associated server.
     *
     * @param string $domain The site's domain name (e.g. example.com).
     * @param ?string $repo   The repository URL for git sites (null if not yet configured).
     * @param ?string $branch The repository branch for git sites (null if not yet configured).
     * @param string $server Server name associated with the site.
     */
    public function __construct(
        public string $domain,
        public ?string $repo,
        public ?string $branch,
        public string $server,
    ) {
    }
}
