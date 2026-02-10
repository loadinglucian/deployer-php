<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\Container;
use DeployerPHP\Enums\WwwMode;
use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Services\DomainClassifierService;

/**
 * Centralized domain operations and validation.
 *
 * @property Container $container
 */
trait DomainOperationsTrait
{
    // ----
    // Normalization
    // ----

    /**
     * Normalize domain name (lowercase and strip www.).
     */
    protected function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }

    /**
     * Detect whether a domain is a subdomain.
     *
     * Uses Public Suffix List (PSL) resolution.
     *
     * @throws ValidationException When domain classification fails
     */
    protected function isSubdomain(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);

        try {
            /** @var DomainClassifierService $classifier */
            $classifier = $this->container->build(DomainClassifierService::class);

            return $classifier->isSubdomain($domain);
        } catch (\RuntimeException $e) {
            throw new ValidationException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Determine whether a site should have a WWW alias.
     *
     * Subdomains never get a WWW alias, regardless of requested mode.
     */
    protected function hasWww(string $domain, string $wwwMode): bool
    {
        if ($this->isSubdomain($domain)) {
            return false;
        }

        return WwwMode::NONE->value !== $wwwMode;
    }

    // ----
    // Validation
    // ----

    /**
     * Validate domain format.
     *
     * Checks:
     * - Must be a non-empty string
     * - Must not end with trailing dot
     * - Must contain a TLD (at least one dot)
     * - RFC 1035: Total length <= 253 characters
     * - RFC 1035: Each label <= 63 characters
     * - Valid hostname format via filter_var
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDomainFormat(mixed $domain): ?string
    {
        if (! is_string($domain)) {
            return 'Domain must be a string';
        }

        // Normalize: trim whitespace once at start
        $domain = trim($domain);

        if ('' === $domain) {
            return 'Domain cannot be empty';
        }

        // Must not end with trailing dot
        if (str_ends_with($domain, '.')) {
            return 'Domain must not end with a dot';
        }

        // Must contain TLD (at least one dot)
        if (! str_contains($domain, '.')) {
            return 'Domain must include a TLD (e.g., example.com)';
        }

        // RFC 1035: Total length limit
        if (253 < strlen($domain)) {
            return 'Domain exceeds maximum length of 253 characters';
        }

        // RFC 1035: Label length limit (63 chars per label)
        $labels = explode('.', $domain);
        foreach ($labels as $label) {
            if (63 < strlen($label)) {
                return 'Domain label exceeds maximum length of 63 characters';
            }
        }

        // PHP built-in domain validation
        if (false === filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return 'Invalid domain format (e.g., example.com, subdomain.example.com)';
        }

        return null;
    }

    /**
     * Validate DNS record name format.
     *
     * Uses the strictest common subset that works across all providers
     * (AWS Route53, Cloudflare, DigitalOcean).
     *
     * Checks:
     * - Must be a non-empty string
     * - "@" is always valid (zone apex)
     * - RFC 1035: Total length <= 253 characters
     * - RFC 1035: Each label <= 63 characters
     * - Valid hostname pattern (no wildcards, underscores, or trailing dots)
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateRecordNameFormat(mixed $name): ?string
    {
        if (! is_string($name)) {
            return 'Record name must be a string';
        }

        $name = trim($name);

        if ('' === $name) {
            return 'Record name cannot be empty';
        }

        // "@" is always valid (zone apex)
        if ('@' === $name) {
            return null;
        }

        // RFC 1035: Total length limit
        if (253 < strlen($name)) {
            return 'Record name exceeds maximum length of 253 characters';
        }

        // RFC 1035: Label length limit (63 chars per label)
        $labels = explode('.', $name);
        foreach ($labels as $label) {
            if (63 < strlen($label)) {
                return 'Record name label exceeds maximum length of 63 characters';
            }
        }

        // Strictest common pattern: alphanumeric with hyphens (no wildcards, underscores, trailing dots)
        if (1 !== preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', $name)) {
            return 'Invalid record name format. Use "@" for root or a valid hostname (e.g., www, api, mail.example)';
        }

        return null;
    }

    /**
     * Validate DNS record value format.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateRecordValueFormat(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'Record value must be a string';
        }

        if ('' === trim($value)) {
            return 'Record value cannot be empty';
        }

        return null;
    }
}
