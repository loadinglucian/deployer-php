<?php

declare(strict_types=1);

namespace DeployerPHP\Builders;

use DeployerPHP\DTOs\CronDTO;
use DeployerPHP\DTOs\SiteDTO;
use DeployerPHP\DTOs\SupervisorDTO;
use DeployerPHP\Enums\WwwMode;

/**
 * Builder for SiteDTO - centralizes all SiteDTO instantiation.
 *
 * Handles nested CronDTO and SupervisorDTO arrays via their respective builders.
 */
final class SiteBuilder
{
    private string $domain = '';
    private ?string $repo = null;
    private ?string $branch = null;
    private string $server = '';
    private string $phpVersion = '';
    private string $wwwMode = WwwMode::UNKNOWN->value;
    private bool $hasWww = false;
    private string $webRoot = 'public';
    /** @var array<int, CronDTO> */
    private array $crons = [];
    /** @var array<int, SupervisorDTO> */
    private array $supervisors = [];

    private function __construct()
    {
    }

    /**
     * Create a new SiteBuilder for fresh creation.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Create a SiteBuilder from an existing SiteDTO.
     */
    public static function from(SiteDTO $dto): self
    {
        return (new self())
            ->domain($dto->domain)
            ->repo($dto->repo)
            ->branch($dto->branch)
            ->server($dto->server)
            ->phpVersion($dto->phpVersion)
            ->wwwMode($dto->wwwMode)
            ->hasWww($dto->hasWww)
            ->webRoot($dto->webRoot)
            ->crons($dto->crons)
            ->supervisors($dto->supervisors);
    }

    /**
     * Create a SiteBuilder from storage data.
     *
     * Hydrates nested crons and supervisors via their respective builders.
     *
     * @param array<string, mixed> $data
     * @throws \RuntimeException If required fields are missing.
     */
    public static function fromStorage(array $data): self
    {
        $domain = $data['domain'] ?? '';
        $repo = $data['repo'] ?? null;
        $branch = $data['branch'] ?? null;
        $server = $data['server'] ?? '';
        $phpVersion = $data['php_version'] ?? null;
        $wwwModeRaw = $data['www_mode'] ?? WwwMode::UNKNOWN->value;
        $hasWwwRaw = $data['has_www'] ?? null;
        $webRoot = $data['web_root'] ?? 'public';
        $cronsData = $data['crons'] ?? [];
        $supervisorsData = $data['supervisors'] ?? [];

        if (! is_string($phpVersion) || '' === $phpVersion) {
            $domainStr = is_string($domain) ? $domain : 'unknown';
            throw new \RuntimeException("Site '{$domainStr}' is missing required 'php_version' in inventory");
        }

        // Hydrate nested crons via CronBuilder
        $crons = [];
        if (is_array($cronsData)) {
            foreach ($cronsData as $cronData) {
                if (is_array($cronData)) {
                    /** @var array<string, mixed> $cronData */
                    $crons[] = CronBuilder::fromStorage($cronData)->build();
                }
            }
        }

        // Hydrate nested supervisors via SupervisorBuilder
        $supervisors = [];
        if (is_array($supervisorsData)) {
            foreach ($supervisorsData as $supervisorData) {
                if (is_array($supervisorData)) {
                    /** @var array<string, mixed> $supervisorData */
                    $supervisors[] = SupervisorBuilder::fromStorage($supervisorData)->build();
                }
            }
        }

        $wwwMode = is_string($wwwModeRaw) && WwwMode::isValid($wwwModeRaw)
            ? $wwwModeRaw
            : WwwMode::UNKNOWN->value;

        $hasWww = is_bool($hasWwwRaw)
            ? $hasWwwRaw
            : self::inferHasWwwFromMode($wwwMode);

        return (new self())
            ->domain(is_string($domain) ? $domain : '')
            ->repo(is_string($repo) ? $repo : null)
            ->branch(is_string($branch) ? $branch : null)
            ->server(is_string($server) ? $server : '')
            ->phpVersion($phpVersion)
            ->wwwMode($wwwMode)
            ->hasWww($hasWww)
            ->webRoot(is_string($webRoot) ? $webRoot : 'public')
            ->crons($crons)
            ->supervisors($supervisors);
    }

    public function domain(string $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    public function repo(?string $repo): self
    {
        $this->repo = $repo;

        return $this;
    }

    public function branch(?string $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    public function server(string $server): self
    {
        $this->server = $server;

        return $this;
    }

    public function phpVersion(string $phpVersion): self
    {
        $this->phpVersion = $phpVersion;

        return $this;
    }

    public function wwwMode(string $wwwMode): self
    {
        if (! WwwMode::isValid($wwwMode)) {
            throw new \RuntimeException(
                "Invalid WWW mode '{$wwwMode}'. Allowed: " . implode(', ', WwwMode::values())
            );
        }

        $this->wwwMode = $wwwMode;

        return $this;
    }

    public function hasWww(bool $hasWww): self
    {
        $this->hasWww = $hasWww;

        return $this;
    }

    public function webRoot(string $webRoot): self
    {
        $this->webRoot = $webRoot;

        return $this;
    }

    /**
     * @param array<int, CronDTO> $crons
     */
    public function crons(array $crons): self
    {
        $this->crons = $crons;

        return $this;
    }

    /**
     * Add a single cron to the existing crons array.
     */
    public function addCron(CronDTO $cron): self
    {
        $this->crons[] = $cron;

        return $this;
    }

    /**
     * @param array<int, SupervisorDTO> $supervisors
     */
    public function supervisors(array $supervisors): self
    {
        $this->supervisors = $supervisors;

        return $this;
    }

    /**
     * Add a single supervisor to the existing supervisors array.
     */
    public function addSupervisor(SupervisorDTO $supervisor): self
    {
        $this->supervisors[] = $supervisor;

        return $this;
    }

    /**
     * Build the SiteDTO.
     *
     * @throws \RuntimeException If required fields are missing.
     */
    public function build(): SiteDTO
    {
        if ('' === $this->domain) {
            throw new \RuntimeException('SiteDTO requires a domain');
        }
        if ('' === $this->server) {
            throw new \RuntimeException('SiteDTO requires a server');
        }
        if ('' === $this->phpVersion) {
            throw new \RuntimeException('SiteDTO requires a phpVersion');
        }

        return new SiteDTO(
            domain: $this->domain,
            repo: $this->repo,
            branch: $this->branch,
            server: $this->server,
            phpVersion: $this->phpVersion,
            wwwMode: $this->wwwMode,
            hasWww: $this->hasWww,
            webRoot: $this->webRoot,
            crons: $this->crons,
            supervisors: $this->supervisors,
        );
    }

    private static function inferHasWwwFromMode(string $wwwMode): bool
    {
        $mode = WwwMode::tryFrom($wwwMode);

        return $mode?->hasWwwAlias() ?? false;
    }
}
