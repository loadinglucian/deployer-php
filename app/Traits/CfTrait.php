<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\Services\Cf\CfDnsService;
use DeployerPHP\Services\CfService;
use DeployerPHP\Services\EnvService;
use DeployerPHP\Services\IoService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable Cloudflare things.
 *
 * @property CfService $cf
 * @property EnvService $env
 * @property IoService $io
 */
trait CfTrait
{
    use DomainOperationsTrait;

    // ----
    // Helpers
    // ----

    //
    // API
    // ----

    /**
     * Initialize Cloudflare API with token from environment.
     *
     * Retrieves the Cloudflare API token from environment variables
     * (CLOUDFLARE_API_TOKEN or CF_API_TOKEN), configures the
     * Cloudflare service, and verifies authentication with a lightweight
     * API call. Displays error messages and exits on failure.
     *
     * @return int Command::SUCCESS on success, Command::FAILURE on error
     */
    protected function initializeCloudflareAPI(): int
    {
        try {
            /** @var string $apiToken */
            $apiToken = $this->env->get(['CLOUDFLARE_API_TOKEN', 'CF_API_TOKEN']);

            $this->io->promptSpin(
                fn () => $this->cf->initialize($apiToken),
                'Initializing Cloudflare API...'
            );

            return Command::SUCCESS;
        } catch (\InvalidArgumentException) {
            $this->nay('Cloudflare API token not found in environment.');
            $this->nay('Set CLOUDFLARE_API_TOKEN or CF_API_TOKEN in your .env file.');

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());
            $this->nay('Check that your Cloudflare API token is valid and has DNS edit permissions.');

            return Command::FAILURE;
        }
    }

    //
    // UI
    // ----

    /**
     * Resolve zone option - supports both zone name and zone ID.
     *
     * @param string $zoneOrDomain Zone name or zone ID
     *
     * @return string Zone ID
     *
     * @throws \RuntimeException If zone not found
     */
    protected function resolveZoneId(string $zoneOrDomain): string
    {
        return $this->io->promptSpin(
            fn () => $this->cf->zone->getZoneId($zoneOrDomain),
            "Resolving zone '{$zoneOrDomain}'..."
        );
    }

    /**
     * Normalize record name - convert "@" to zone apex.
     *
     * @param string $name     Record name ("@" for root or subdomain)
     * @param string $zoneName Zone domain name
     *
     * @return string Fully qualified record name
     */
    protected function normalizeRecordName(string $name, string $zoneName): string
    {
        if ('@' === $name) {
            return $zoneName;
        }

        // If name already includes zone, return as-is
        if (str_ends_with($name, '.' . $zoneName) || $name === $zoneName) {
            return $name;
        }

        // Append zone to subdomain
        return $name . '.' . $zoneName;
    }

    // ----
    // Validation
    // ----

    /**
     * Validate zone input (domain name only).
     *
     * Zone ID resolution is handled by resolveZoneId() via API lookup.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateZoneInput(mixed $zone): ?string
    {
        if (! is_string($zone)) {
            return 'Zone must be a string';
        }

        if ('' === trim($zone)) {
            return 'Zone cannot be empty';
        }

        return $this->validateDomainFormat($zone);
    }

    /**
     * Validate record type input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateRecordTypeInput(mixed $type): ?string
    {
        if (!is_string($type)) {
            return 'Record type must be a string';
        }

        if ('' === trim($type)) {
            return 'Record type cannot be empty';
        }

        $validTypes = CfDnsService::RECORD_TYPES;
        $upperType = strtoupper($type);

        if (!in_array($upperType, $validTypes, true)) {
            return "Invalid record type: '{$type}'. Valid types: " . implode(', ', $validTypes);
        }

        return null;
    }

    /**
     * Validate record name input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateRecordNameInput(mixed $name): ?string
    {
        return $this->validateRecordNameFormat($name);
    }

    /**
     * Validate record value input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateRecordValueInput(mixed $value): ?string
    {
        return $this->validateRecordValueFormat($value);
    }

    /**
     * Validate TTL input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateTtlInput(mixed $ttl): ?string
    {
        if (!is_string($ttl) && !is_int($ttl)) {
            return 'TTL must be a number';
        }

        if (is_string($ttl) && !is_numeric($ttl)) {
            return 'TTL must be a number';
        }

        $ttlInt = is_string($ttl) ? (int) $ttl : $ttl;

        if (1 !== $ttlInt && (60 > $ttlInt || 86400 < $ttlInt)) {
            return 'TTL must be 1 (auto) or between 60 and 86400 seconds';
        }

        return null;
    }

}
