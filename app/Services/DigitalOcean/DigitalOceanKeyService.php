<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Services\DigitalOcean;

use Bigpixelrocket\DeployerPHP\Services\FilesystemService;
use DigitalOceanV2\Client;

/**
 * DigitalOcean SSH key management service.
 *
 * Handles uploading and deleting SSH keys from DigitalOcean account.
 */
class DigitalOceanKeyService
{
    private ?Client $api = null;

    public function __construct(
        private readonly FilesystemService $fs,
    ) {
    }

    /**
     * Set the DigitalOcean API client.
     */
    public function setAPI(Client $api): void
    {
        $this->api = $api;
    }

    /**
     * Upload a local SSH public key to DigitalOcean account.
     *
     * @param string $publicKeyPath Path to public key file (should be already expanded)
     * @param string $keyName Name for the key in DO account
     *
     * @return int The new SSH key ID
     *
     * @throws \RuntimeException If upload fails
     */
    public function uploadKey(string $publicKeyPath, string $keyName): int
    {
        // Check if file exists
        if (!$this->fs->exists($publicKeyPath)) {
            throw new \RuntimeException("SSH public key file not found: {$publicKeyPath}");
        }

        // Read public key content
        $publicKey = $this->fs->readFile($publicKeyPath);
        $publicKey = trim($publicKey);

        // Validate key format (should start with ssh-rsa, ssh-ed25519, ecdsa-sha2-nistp256, etc.)
        // except 'ssh-dss' which is effectively obsolete:
        $validPrefixes = ['ssh-rsa', 'ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-dss'];
        $isValid = false;
        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($publicKey, $prefix)) {
                $isValid = true;
                break;
            }
        }

        if (!$isValid) {
            throw new \RuntimeException("Invalid SSH public key format in {$publicKeyPath}");
        }

        $client = $this->getAPI();

        try {
            $keyApi = $client->key();
            $key = $keyApi->create($keyName, $publicKey);

            return $key->id;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to upload SSH key: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Delete an SSH key from DigitalOcean account.
     *
     * Silently succeeds if key doesn't exist (404).
     *
     * @param int $keyId SSH key ID to delete
     *
     * @throws \RuntimeException If deletion fails (non-404 errors)
     */
    public function deleteKey(int $keyId): void
    {
        $client = $this->getAPI();

        try {
            $keyApi = $client->key();
            $keyApi->remove((string) $keyId);
        } catch (\Throwable $e) {
            // Check if 404 (already deleted) - silently succeed
            $message = strtolower($e->getMessage());
            if (str_contains($message, '404') || str_contains($message, 'not found')) {
                return;
            }

            // Other errors - throw
            throw new \RuntimeException("Failed to delete SSH key: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get the configured DigitalOcean API client.
     *
     * @throws \RuntimeException If client not configured
     */
    private function getAPI(): Client
    {
        if ($this->api === null) {
            throw new \RuntimeException('DigitalOcean API client not configured. Call setAPI() first.');
        }

        return $this->api;
    }
}
