<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\Repositories\ServerRepository;

/**
 * Validation helpers for server configuration.
 *
 * Requires classes using this trait to have a ServerRepository property.
 *
 * @property ServerRepository $servers
 */
trait ServerValidationTrait
{
    /**
     * Validate server name format and uniqueness.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateNameInput(mixed $name): ?string
    {
        if (!is_string($name)) {
            return 'Server name must be a string';
        }

        // Check if empty
        if (trim($name) === '') {
            return 'Server name cannot be empty';
        }

        // Check uniqueness
        $existing = $this->servers->findByName($name);
        if ($existing !== null) {
            return "Server '{$name}' already exists in inventory";
        }

        return null;
    }

    /**
     * Validate host is a valid IP or domain and unique.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateHostInput(mixed $host): ?string
    {
        if (!is_string($host)) {
            return 'Host must be a string';
        }

        // Check format
        $isValidIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isValidDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if (!$isValidIp && !$isValidDomain) {
            return 'Must be a valid IP address or domain name (e.g., 192.168.1.100, example.com)';
        }

        // Check uniqueness
        $existing = $this->servers->findByHost($host);
        if ($existing !== null) {
            return "Host '{$host}' is already used by server '{$existing->name}'";
        }

        return null;
    }

    /**
     * Validate port is in valid range.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validatePortInput(mixed $portString): ?string
    {
        if (!is_string($portString)) {
            return 'Port must be a string';
        }

        if (!ctype_digit($portString)) {
            return 'Port must be a number';
        }

        $port = (int) $portString;
        if ($port < 1 || $port > 65535) {
            return 'Port must be between 1 and 65535 (common SSH ports: 22, 2222, 22000)';
        }

        return null;
    }
}
