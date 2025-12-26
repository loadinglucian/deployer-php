<?php

declare(strict_types=1);

namespace DeployerPHP\Services\Aws;

use DeployerPHP\Services\FilesystemService;

/**
 * AWS EC2 key pair management service.
 *
 * Handles importing and deleting SSH key pairs from AWS account.
 */
class AwsKeyService extends BaseAwsService
{
    public function __construct(
        private readonly FilesystemService $fs,
    ) {
    }

    /**
     * Import a local SSH public key to AWS as an EC2 key pair.
     *
     * @param string $publicKeyPath Path to public key file
     * @param string $keyName Name for the key pair in AWS
     *
     * @return string The key fingerprint
     *
     * @throws \RuntimeException If import fails
     */
    public function importKeyPair(string $publicKeyPath, string $keyName): string
    {
        $publicKey = $this->fs->readFile($publicKeyPath);
        $publicKey = trim($publicKey);

        $ec2 = $this->createEc2Client();

        try {
            $result = $ec2->importKeyPair([
                'KeyName' => $keyName,
                'PublicKeyMaterial' => $publicKey,
            ]);

            /** @var string $fingerprint */
            $fingerprint = $result['KeyFingerprint'];

            return $fingerprint;
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            // Provide clearer message for duplicate key
            if (str_contains($message, 'already exists')) {
                throw new \RuntimeException("Key pair '{$keyName}' already exists in this region", 0, $e);
            }

            throw new \RuntimeException("Failed to import key pair: {$message}", 0, $e);
        }
    }

    /**
     * Delete a key pair from AWS.
     *
     * Silently succeeds if key pair doesn't exist.
     *
     * @param string $keyName Key pair name to delete
     *
     * @throws \RuntimeException If deletion fails (non-404 errors)
     */
    public function deleteKeyPair(string $keyName): void
    {
        $ec2 = $this->createEc2Client();

        try {
            $ec2->deleteKeyPair([
                'KeyName' => $keyName,
            ]);
        } catch (\Throwable $e) {
            // AWS deleteKeyPair is idempotent - it doesn't error on missing keys
            // But we still catch for other potential errors
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'not found') || str_contains($message, 'does not exist')) {
                return;
            }

            throw new \RuntimeException("Failed to delete key pair: {$e->getMessage()}", 0, $e);
        }
    }
}
