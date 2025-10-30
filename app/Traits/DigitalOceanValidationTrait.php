<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

/**
 * Validation helpers for DigitalOcean droplet provisioning.
 */
trait DigitalOceanValidationTrait
{
    /**
     * Validate region against available regions.
     *
     * @param array<string, string> $validRegions Available regions from account
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateRegionInput(mixed $region, array $validRegions): ?string
    {
        if (!is_string($region)) {
            return 'Region must be a string';
        }

        if (trim($region) === '') {
            return 'Region cannot be empty';
        }

        // Check if region exists in account's available regions
        if (!isset($validRegions[$region])) {
            return "Invalid region: '{$region}' is not available in your DigitalOcean account";
        }

        return null;
    }

    /**
     * Validate size against available droplet sizes.
     *
     * @param array<string, string> $validSizes Available sizes from account
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateSizeInput(mixed $size, array $validSizes): ?string
    {
        if (!is_string($size)) {
            return 'Size must be a string';
        }

        if (trim($size) === '') {
            return 'Size cannot be empty';
        }

        // Check if size exists in account's available sizes
        if (!isset($validSizes[$size])) {
            return "Invalid size: '{$size}' is not available in your DigitalOcean account";
        }

        return null;
    }

    /**
     * Validate image against available images.
     *
     * @param array<string, string> $validImages Available images from account
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateImageInput(mixed $image, array $validImages): ?string
    {
        if (!is_string($image)) {
            return 'Image must be a string';
        }

        if (trim($image) === '') {
            return 'Image cannot be empty';
        }

        // Check if image exists in account's available images
        if (!isset($validImages[$image])) {
            return "Invalid image: '{$image}' is not available in your DigitalOcean account";
        }

        return null;
    }

    /**
     * Validate VPC UUID format.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateVpcUuidInput(mixed $uuid): ?string
    {
        if (!is_string($uuid)) {
            return 'VPC UUID must be a string';
        }

        // Empty is allowed (optional) - will use default VPC
        if (trim($uuid) === '' || $uuid === 'default') {
            return null;
        }

        // Validate RFC 4122 UUID format
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (!preg_match($uuidPattern, $uuid)) {
            return 'VPC UUID must be a valid UUID (e.g., 12345678-1234-1234-1234-123456789abc) or "default"';
        }

        return null;
    }

    /**
     * Validate single SSH key ID against available keys.
     *
     * @param array<int, string> $validKeys Available SSH keys from account (key ID => description)
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateSshKeyInput(mixed $keyId, array $validKeys): ?string
    {
        if (!is_string($keyId) && !is_int($keyId)) {
            return 'SSH key ID must be a string or integer';
        }

        // Convert to integer for validation
        $keyIdInt = is_int($keyId) ? $keyId : (int) $keyId;

        // Check if key exists in account's available keys
        if (!isset($validKeys[$keyIdInt])) {
            return "Invalid SSH key: ID {$keyIdInt} is not available in your DigitalOcean account";
        }

        return null;
    }

    /**
     * Validate comma-separated SSH key IDs.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateSshKeysInput(mixed $keys): ?string
    {
        if (!is_string($keys)) {
            return 'SSH keys must be a string';
        }

        // Empty is allowed (optional)
        if (trim($keys) === '') {
            return null;
        }

        // Split and validate each key ID
        $keyArray = array_map(trim(...), explode(',', $keys));
        foreach ($keyArray as $key) {
            if ($key === '') {
                return 'SSH key IDs cannot be empty (remove extra commas)';
            }

            // Keys should be numeric IDs or fingerprints
            $isNumeric = ctype_digit($key);
            $isFingerprint = preg_match('/^[a-f0-9:]{47,95}$/i', $key) === 1;

            if (!$isNumeric && !$isFingerprint) {
                return "Invalid SSH key format: '{$key}' (must be numeric ID or fingerprint)";
            }
        }

        return null;
    }
}
