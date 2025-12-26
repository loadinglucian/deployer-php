<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

use DeployerPHP\Exceptions\ValidationException;
use DeployerPHP\Services\DoService;
use DeployerPHP\Services\EnvService;
use DeployerPHP\Services\IoService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable DigitalOcean things.
 *
 * @property DoService $do
 * @property EnvService $env
 * @property IoService $io
 */
trait DoTrait
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
    protected function initializeDoAPI(): int
    {
        try {
            /** @var string $apiToken */
            $apiToken = $this->env->get(['DIGITALOCEAN_API_TOKEN', 'DO_API_TOKEN']);

            // Initialize DigitalOcean API
            $this->io->promptSpin(
                fn () => $this->do->initialize($apiToken),
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
    protected function ensureDoKeysAvailable(?array $keys = null): array|int
    {
        //
        // Get all keys

        if (null === $keys) {
            try {
                $keys = $this->do->account->getPublicKeys();
            } catch (\RuntimeException $e) {
                $this->nay('Failed to retrieve public SSH keys: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        //
        // Check if no keys are available

        if (0 === count($keys)) {
            $this->info('No public SSH keys found in your DigitalOcean account');
            $this->ul([
                'Run <fg=cyan>pro:do:key:add</> to add a public SSH key',
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
    protected function selectDoKey(): array|int
    {
        //
        // Get all keys

        $availableKeys = $this->ensureDoKeysAvailable();

        if (is_int($availableKeys)) {
            return Command::FAILURE;
        }

        //
        // Prompt for selection

        try {
            /** @var string|int $selectedKey */
            $selectedKey = $this->io->getValidatedOptionOrPrompt(
                'key',
                fn ($validate): string => (string) $this->io->promptSelect(
                    label: 'Select public SSH key:',
                    options: $availableKeys,
                    validate: $validate
                ),
                fn ($value) => $this->validateDoKeySelection($value, $availableKeys)
            );
        } catch (ValidationException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        /** @var string $description */
        $description = $availableKeys[$selectedKey];

        return [
            'id' => $selectedKey,
            'description' => $description,
        ];
    }

    // ----
    // Validation
    // ----

    /**
     * Validate image against available images.
     *
     * @param array<string, string> $validImages Available images from account
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDoDropletImage(mixed $image, array $validImages): ?string
    {
        if (!is_string($image)) {
            return 'Droplet image must be a string';
        }

        if ('' === trim($image)) {
            return 'Droplet image cannot be empty';
        }

        // Check if image exists in account's available images
        if (!isset($validImages[$image])) {
            return "Invalid droplet image: '{$image}' is not available in your DigitalOcean account";
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
    protected function validateDoDropletSize(mixed $size, array $validSizes): ?string
    {
        if (!is_string($size)) {
            return 'Droplet size must be a string';
        }

        if ('' === trim($size)) {
            return 'Droplet size cannot be empty';
        }

        // Check if size exists in account's available sizes
        if (!isset($validSizes[$size])) {
            return "Invalid droplet size: '{$size}' is not available in your DigitalOcean account";
        }

        return null;
    }

    /**
     * Validate region against available regions.
     *
     * @param array<string, string> $validRegions Available regions from account
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDoRegion(mixed $region, array $validRegions): ?string
    {
        if (!is_string($region)) {
            return 'Region must be a string';
        }

        if ('' === trim($region)) {
            return 'Region cannot be empty';
        }

        // Check if region exists in account's available regions
        if (!isset($validRegions[$region])) {
            return "Invalid region: '{$region}' is not available in your DigitalOcean account";
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
    protected function validateDoSSHKey(mixed $keyId, array $validKeys): ?string
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

    /**
     * Validate VPC UUID format and existence.
     *
     * @param array<string, string> $availableVpcs Available VPCs (UUID => name)
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDoVPCUUID(mixed $uuid, array $availableVpcs = []): ?string
    {
        if (!is_string($uuid)) {
            return 'VPC UUID must be a string';
        }

        // Empty is allowed (optional) - will use default VPC
        if ('' === trim($uuid) || 'default' === $uuid) {
            return null;
        }

        // Validate RFC 4122 UUID format
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (!preg_match($uuidPattern, $uuid)) {
            return 'VPC UUID must be a valid UUID (e.g., 12345678-1234-1234-1234-123456789abc) or "default"';
        }

        // Validate VPC exists in available VPCs for region
        if ([] !== $availableVpcs && !isset($availableVpcs[$uuid])) {
            return sprintf('VPC not found in region. Available: %s', implode(', ', array_keys($availableVpcs)));
        }

        return null;
    }

    /**
     * Validate key selection exists in available keys.
     *
     * @param array<int|string, string> $validKeys Available SSH keys from account (key ID => description)
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDoKeySelection(mixed $keyId, array $validKeys): ?string
    {
        if (! is_string($keyId) && ! is_int($keyId)) {
            return 'Key ID must be a string or integer';
        }

        // Allow loose comparison for string/int key IDs
        $keyIds = array_keys($validKeys);
        if (! in_array($keyId, $keyIds, false)) {
            return 'SSH key not found';
        }

        return null;
    }
}
