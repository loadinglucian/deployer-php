<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\Services\DigitalOceanService;
use Deployer\Services\EnvService;
use Deployer\Services\IOService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable DigitalOcean things.
 *
 * @property DigitalOceanService $digitalOcean
 * @property EnvService $env
 * @property IOService $io
 */
trait DigitalOceanTrait
{
    // ----
    // Helpers
    // ----

    //
    // API
    // ----

    /**
     * Initialize DigitalOcean API with token from environment.
     *
     * Retrieves the DigitalOcean API token from environment variables
     * (DIGITALOCEAN_API_TOKEN or DO_API_TOKEN), configures the
     * DigitalOcean service, and verifies authentication with a lightweight
     * API call. Displays error messages and exits on failure.
     *
     * @return int Command::SUCCESS on success, Command::FAILURE on error
     */
    protected function initializeDigitalOceanAPI(): int
    {
        try {
            /** @var string $apiToken */
            $apiToken = $this->env->get(['DIGITALOCEAN_API_TOKEN', 'DO_API_TOKEN']);

            // Initialize DigitalOcean API
            $this->io->promptSpin(
                fn () => $this->digitalOcean->initialize($apiToken),
                'Initializing DigitalOcean API...'
            );

            return Command::SUCCESS;
        } catch (\InvalidArgumentException) {
            // Token configuration issue
            $this->nay('DigitalOcean API token not found in environment.');
            $this->nay('Set DIGITALOCEAN_API_TOKEN or DO_API_TOKEN in your .env file.');

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            // API authentication failure
            $this->nay($e->getMessage());
            $this->nay('Check that your DIGITALOCEAN_API_TOKEN or DO_API_TOKEN is valid and has not expired.');

            return Command::FAILURE;
        }
    }

    //
    // UI
    // ----

    /**
     * Display a warning if no keys are available. Otherwise, return all keys.
     *
     * @param array<int|string, string>|null $keys Optional pre-fetched keys; if null, fetches from DigitalOcean API
     * @return array<int|string, string>|int Returns array of keys (ID => description) or Command::FAILURE
     */
    protected function ensureKeysAvailable(?array $keys = null): array|int
    {
        //
        // Get all keys

        if ($keys === null) {
            try {
                $keys = $this->digitalOcean->account->getPublicKeys();
            } catch (\RuntimeException $e) {
                $this->nay('Failed to retrieve public SSH keys: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        //
        // Check if no keys are available

        if (count($keys) === 0) {
            $this->info('No public SSH keys found in your DigitalOcean account');
            $this->ul([
                'Run <fg=cyan>key:add:digitalocean</> to add a public SSH key',
            ]);

            return Command::FAILURE;
        }

        return $keys;
    }

    /**
     * Select a key from available keys via option or interactive prompt.
     *
     * @return array{id: string|int, description: string}|int Array with selected key ID and description on success, or Command::FAILURE on error
     */
    protected function selectKey(): array|int
    {
        //
        // Get all keys

        $availableKeys = $this->ensureKeysAvailable();

        if (is_int($availableKeys)) {
            return Command::FAILURE;
        }

        //
        // Prompt for selection

        /** @var string $selectedKey */
        $selectedKey = $this->io->getOptionOrPrompt(
            'key',
            fn (): string => (string) $this->io->promptSelect(
                label: 'Select public SSH key:',
                options: $availableKeys
            )
        );

        //
        // Validate key exists (in case user passed --key option)

        if (!isset($availableKeys[$selectedKey])) {
            $this->nay("Public SSH key '{$selectedKey}' not found");

            return Command::FAILURE;
        }

        return [
            'id' => $selectedKey,
            'description' => $availableKeys[$selectedKey],
        ];
    }

    // ----
    // Validation
    // ----

    /**
     * Validate region against available regions.
     *
     * @param array<string, string> $validRegions Available regions from account
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDigitalOceanRegion(mixed $region, array $validRegions): ?string
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
    protected function validateDigitalOceanDropletSize(mixed $size, array $validSizes): ?string
    {
        if (!is_string($size)) {
            return 'Droplet size must be a string';
        }

        if (trim($size) === '') {
            return 'Droplet size cannot be empty';
        }

        // Check if size exists in account's available sizes
        if (!isset($validSizes[$size])) {
            return "Invalid droplet size: '{$size}' is not available in your DigitalOcean account";
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
    protected function validateDigitalOceanDropletImage(mixed $image, array $validImages): ?string
    {
        if (!is_string($image)) {
            return 'Droplet image must be a string';
        }

        if (trim($image) === '') {
            return 'Droplet image cannot be empty';
        }

        // Check if image exists in account's available images
        if (!isset($validImages[$image])) {
            return "Invalid droplet image: '{$image}' is not available in your DigitalOcean account";
        }

        return null;
    }

    /**
     * Validate VPC UUID format.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDigitalOceanVPCUUID(mixed $uuid): ?string
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
    protected function validateDigitalOceanSSHKey(mixed $keyId, array $validKeys): ?string
    {
        if (!is_string($keyId) && !is_int($keyId)) {
            return 'SSH key ID must be a string or integer';
        }

        // Validate numeric string
        if (is_string($keyId) && !ctype_digit($keyId)) {
            return 'SSH key ID must be numeric';
        }

        // Convert to integer for validation
        $keyIdInt = is_int($keyId) ? $keyId : (int) $keyId;

        // Check if key exists in account's available keys
        if (!isset($validKeys[$keyIdInt])) {
            return "Invalid SSH key: ID {$keyIdInt} is not available in your DigitalOcean account";
        }

        return null;
    }

}
