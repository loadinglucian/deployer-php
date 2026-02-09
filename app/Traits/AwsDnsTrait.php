<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\Services\Aws\AwsRoute53DnsService;
use DeployerPHP\Services\AwsService;
use DeployerPHP\Services\IoService;

/**
 * Reusable AWS Route53 DNS things.
 *
 * @property AwsService $aws
 * @property IoService $io
 */
trait AwsDnsTrait
{
    use DomainOperationsTrait;

    // ----
    // Helpers
    // ----

    /**
     * Resolve and validate a hosted zone exists in the account.
     *
     * @param string $zoneOrDomain Zone ID or domain name
     *
     * @return string The hosted zone ID
     *
     * @throws \RuntimeException If zone not found
     */
    protected function resolveAwsHostedZoneId(string $zoneOrDomain): string
    {
        return $this->io->promptSpin(
            fn () => $this->aws->route53Zone->getHostedZoneId($zoneOrDomain),
            'Validating hosted zone...'
        );
    }

    /**
     * Normalize record name for Route53.
     *
     * Converts "@" to the zone name and ensures the name is fully qualified.
     *
     * @param string $name Record name
     * @param string $zoneName Zone name (without trailing dot)
     *
     * @return string Fully qualified record name (without trailing dot)
     */
    protected function normalizeAwsRecordName(string $name, string $zoneName): string
    {
        // Strip trailing dot if present (user may include it)
        $name = rtrim($name, '.');

        // "@" means the zone apex
        if ('@' === $name) {
            return $zoneName;
        }

        // If exact match or proper subdomain (with dot separator), return as-is
        if ($name === $zoneName || str_ends_with($name, '.' . $zoneName)) {
            return $name;
        }

        // Append zone name
        return $name . '.' . $zoneName;
    }

    // ----
    // Validation
    // ----

    /**
     * Validate hosted zone input (domain name only).
     *
     * Zone ID resolution is handled by resolveAwsHostedZoneId() via API lookup.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsHostedZoneInput(mixed $zone): ?string
    {
        if (! is_string($zone)) {
            return 'Hosted zone must be a string';
        }

        if ('' === trim($zone)) {
            return 'Hosted zone cannot be empty';
        }

        return $this->validateDomainFormat($zone);
    }

    /**
     * Validate record type input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsRecordTypeInput(mixed $type): ?string
    {
        if (!is_string($type)) {
            return 'Record type must be a string';
        }

        $type = strtoupper(trim($type));

        if (!in_array($type, AwsRoute53DnsService::RECORD_TYPES, true)) {
            return sprintf(
                "Invalid record type '%s'. Valid types: %s",
                $type,
                implode(', ', AwsRoute53DnsService::RECORD_TYPES)
            );
        }

        return null;
    }

    /**
     * Validate record name input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsRecordNameInput(mixed $name): ?string
    {
        return $this->validateRecordNameFormat($name);
    }

    /**
     * Validate record value input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsRecordValueInput(mixed $value): ?string
    {
        return $this->validateRecordValueFormat($value);
    }

    /**
     * Validate TTL input.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateAwsTtlInput(mixed $ttl): ?string
    {
        if (!is_string($ttl) && !is_int($ttl)) {
            return 'TTL must be a number';
        }

        if (is_string($ttl) && !is_numeric($ttl)) {
            return 'TTL must be a number';
        }

        $ttlInt = is_int($ttl) ? $ttl : (int) $ttl;

        // Route53 minimum TTL is 1 second
        if ($ttlInt < 1 || $ttlInt > 2147483647) {
            return 'TTL must be between 1 and 2147483647 seconds';
        }

        return null;
    }
}
