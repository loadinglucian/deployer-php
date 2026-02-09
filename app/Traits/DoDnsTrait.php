<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\Services\Do\DoDnsService;
use DeployerPHP\Services\DoService;
use DeployerPHP\Services\IoService;

/**
 * Reusable DigitalOcean DNS things.
 *
 * @property DoService $do
 * @property IoService $io
 */
trait DoDnsTrait
{
    use DomainOperationsTrait;

    // ----
    // Helpers
    // ----

    /**
     * Resolve and validate a domain exists in the account.
     *
     * @param string $domain Domain name to resolve
     *
     * @return string The validated domain name
     *
     * @throws \RuntimeException If domain not found
     */
    protected function resolveDoDomain(string $domain): string
    {
        return $this->io->promptSpin(
            fn () => $this->do->domain->getDomainName($domain),
            'Validating domain...'
        );
    }

    /**
     * Normalize record name.
     *
     * Converts "@" to the zone apex representation.
     * DigitalOcean uses "@" for the apex, so we just pass through.
     *
     * @param string $name Record name
     * @param string $domain Domain name (unused, kept for consistency)
     *
     * @return string Normalized record name
     */
    protected function normalizeDoRecordName(string $name, string $domain): string
    {
        // DigitalOcean uses "@" natively for the apex
        return $name;
    }

    // ----
    // Validation
    // ----

    /**
     * Validate domain input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDoDomainInput(mixed $domain): ?string
    {
        if (! is_string($domain)) {
            return 'Domain must be a string';
        }

        if ('' === trim($domain)) {
            return 'Domain cannot be empty';
        }

        return $this->validateDomainFormat($domain);
    }

    /**
     * Validate record type input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDoRecordTypeInput(mixed $type): ?string
    {
        if (!is_string($type)) {
            return 'Record type must be a string';
        }

        $type = strtoupper(trim($type));

        if (!in_array($type, DoDnsService::RECORD_TYPES, true)) {
            return sprintf(
                "Invalid record type '%s'. Valid types: %s",
                $type,
                implode(', ', DoDnsService::RECORD_TYPES)
            );
        }

        return null;
    }

    /**
     * Validate record name input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDoRecordNameInput(mixed $name): ?string
    {
        return $this->validateRecordNameFormat($name);
    }

    /**
     * Validate record value/data input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDoRecordValueInput(mixed $value): ?string
    {
        return $this->validateRecordValueFormat($value);
    }

    /**
     * Validate TTL input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDoTtlInput(mixed $ttl): ?string
    {
        if (!is_string($ttl) && !is_int($ttl)) {
            return 'TTL must be a number';
        }

        if (is_string($ttl) && !is_numeric($ttl)) {
            return 'TTL must be a number';
        }

        $ttlInt = is_int($ttl) ? $ttl : (int) $ttl;

        // DigitalOcean minimum TTL is 30 seconds
        if ($ttlInt < 30 || $ttlInt > 86400) {
            return 'TTL must be between 30 and 86400 seconds';
        }

        return null;
    }

}
