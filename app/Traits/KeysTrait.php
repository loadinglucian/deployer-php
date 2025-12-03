<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\Services\FilesystemService;

/**
 * Reusable SSH key things.
 *
 * Requires classes using this trait to have FilesystemService property.
 *
 * @property FilesystemService $fs
 */
trait KeysTrait
{
    // ----
    // Helpers
    // ----

    //
    // Key resolution
    // ----

    /**
     * Resolve a usable private key path.
     *
     * Priority order:
     * 1. Provided path (with ~ expansion)
     * 2. ~/.ssh/id_ed25519
     * 3. ~/.ssh/id_rsa
     */
    protected function resolvePrivateKeyPath(?string $path): ?string
    {
        return $this->resolveKeyWithFallback($path, [
            '~/.ssh/id_ed25519',
            '~/.ssh/id_rsa',
        ]);
    }

    /**
     * Resolve a usable public key path.
     *
     * Priority order:
     * 1. Provided path (with ~ expansion)
     * 2. ~/.ssh/id_ed25519.pub
     * 3. ~/.ssh/id_rsa.pub
     */
    protected function resolvePublicKeyPath(?string $path): ?string
    {
        return $this->resolveKeyWithFallback($path, [
            '~/.ssh/id_ed25519.pub',
            '~/.ssh/id_rsa.pub',
        ]);
    }

    /**
     * Resolve a key path with fallback to default locations.
     *
     * Priority order:
     * 1. Provided path (with ~ expansion)
     * 2. Fallback paths
     *
     * @param string|null $path The path to resolve
     * @param array<int, string> $fallback The fallback paths
     * @return string|null The resolved path, or null if not found
     */
    protected function resolveKeyWithFallback(?string $path, array $fallback): ?string
    {
        $candidates = [];

        if (is_string($path) && $path !== '') {
            $candidates[] = $path;
        }

        $candidates = array_merge($candidates, $fallback);

        return $this->fs->getFirstExisting($candidates);
    }

    // ----
    // Validation
    // ----

    /**
     * Validate SSH public key file:
     *
     * - Checks if file exists (automatically expands tilde ~ to home directory)
     * - Validates key format
     * - Empty paths are allowed for default key resolution
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateKeyPathInput(mixed $path): ?string
    {
        if (!is_string($path)) {
            return 'Key path must be a string';
        }

        // Allow empty paths (will trigger default key resolution)
        if (trim($path) === '') {
            return null;
        }

        try {
            $expandedPath = $this->fs->expandPath($path);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        // Check if file exists
        if (!$this->fs->exists($expandedPath)) {
            return "SSH key file not found: {$path}";
        }

        // Read and validate key format
        try {
            $key = $this->fs->readFile($expandedPath);
            $key = trim((string) $key);

            // Validate key format (should start with supported SSH key types)
            $validPrefixes = [
                'ssh-ed25519',
                'ecdsa-sha2-nistp256',
                'ecdsa-sha2-nistp384',
                'ecdsa-sha2-nistp521',
                'ssh-rsa',
                // Modern FIDO2/U2F security key types:
                'sk-ssh-ed25519@openssh.com',
                'sk-ecdsa-sha2-nistp256@openssh.com',
                // Obsolete and insecure:
                // 'ssh-dss',
            ];

            $isValid = false;
            foreach ($validPrefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $isValid = true;
                    break;
                }
            }

            if (!$isValid) {
                // Explicit error for obsolete DSA keys
                if (str_starts_with($key, 'ssh-dss')) {
                    return 'DSA (ssh-dss) keys are obsolete and insecure';
                }

                return 'Invalid SSH public key format';
            }
        } catch (\Throwable) {
            return 'Could not read SSH key file';
        }

        return null;
    }

    /**
     * Validate SSH key name format.
     *
     * Ensures name contains only alphanumeric characters, hyphens, and underscores.
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateKeyNameInput(mixed $name): ?string
    {
        if (!is_string($name)) {
            return 'Key name must be a string';
        }

        // Check if empty
        if (trim($name) === '') {
            return 'Key name cannot be empty';
        }

        // Validate format (alphanumeric, hyphens, underscores)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            return 'Key name can only contain letters, numbers, hyphens, and underscores';
        }

        return null;
    }

    /**
     * Validate SSH private key file.
     *
     * - Checks if file exists (automatically expands tilde ~ to home directory)
     * - Validates key format (PEM format with BEGIN/END markers)
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validatePrivateKeyPathInput(mixed $path): ?string
    {
        if (!is_string($path)) {
            return 'Key path must be a string';
        }

        if (trim($path) === '') {
            return 'Private key path cannot be empty';
        }

        try {
            $expandedPath = $this->fs->expandPath($path);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        if (!$this->fs->exists($expandedPath)) {
            return "SSH private key file not found: {$path}";
        }

        try {
            $key = $this->fs->readFile($expandedPath);
            $key = trim((string) $key);

            // Validate key format (should be PEM format with BEGIN marker)
            $validPrefixes = [
                '-----BEGIN OPENSSH PRIVATE KEY-----',
                '-----BEGIN RSA PRIVATE KEY-----',
                '-----BEGIN EC PRIVATE KEY-----',
                '-----BEGIN DSA PRIVATE KEY-----',
                '-----BEGIN PRIVATE KEY-----',
            ];

            $isValid = false;
            foreach ($validPrefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $isValid = true;
                    break;
                }
            }

            if (!$isValid) {
                return 'Invalid SSH private key format';
            }

            // Check for DSA key (obsolete)
            if (str_starts_with($key, '-----BEGIN DSA PRIVATE KEY-----')) {
                return 'DSA keys are obsolete and insecure';
            }
        } catch (\Throwable) {
            return 'Could not read SSH private key file';
        }

        return null;
    }

    /**
     * Validate deploy key pair (private key + corresponding public key).
     *
     * Validates both the private key and its corresponding public key file
     * (expected at same path + '.pub').
     *
     * @return string|null Error message if invalid, null if valid
     */
    protected function validateDeployKeyPairInput(mixed $path): ?string
    {
        // First validate the private key
        $privateKeyError = $this->validatePrivateKeyPathInput($path);
        if ($privateKeyError !== null) {
            return $privateKeyError;
        }

        // Then validate the corresponding public key
        /** @var string $path */
        $publicKeyPath = $path . '.pub';
        $publicKeyError = $this->validateKeyPathInput($publicKeyPath);

        if ($publicKeyError !== null) {
            return "Public key not found or invalid: {$publicKeyPath}";
        }

        return null;
    }
}
