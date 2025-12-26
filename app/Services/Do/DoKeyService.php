<?php

declare(strict_types=1);

namespace DeployerPHP\Services\Do;

use DeployerPHP\Services\FilesystemService;
use DigitalOceanV2\Exception\ResourceNotFoundException;

/**
 * DigitalOcean SSH key management service.
 *
 * Handles uploading and deleting SSH keys from DigitalOcean account.
 */
class DoKeyService extends BaseDoService
{
    public function __construct(
        private readonly FilesystemService $fs,
    ) {
    }

    /**
     * Upload a local SSH public key to DigitalOcean account.
     *
     * @param string $publicKeyPath Path to public key file
     * @param string $keyName Name for the key in DO account
     *
     * @return int The new SSH key ID
     *
     * @throws \RuntimeException If upload fails
     */
    public function uploadPublicKey(string $publicKeyPath, string $keyName): int
    {
        $publicKey = $this->fs->readFile($publicKeyPath);
        $publicKey = trim($publicKey);

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
    public function deletePublicKey(int $keyId): void
    {
        $client = $this->getAPI();

        try {
            $keyApi = $client->key();
            $keyApi->remove((string) $keyId);
        } catch (ResourceNotFoundException) {
            // Already deleted - silently succeed
            return;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to delete SSH key: {$e->getMessage()}", 0, $e);
        }
    }
}
