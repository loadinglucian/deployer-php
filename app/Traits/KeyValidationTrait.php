<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

/**
 * Common SSH key validation helpers for commands.
 */
trait KeyValidationTrait
{
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

        $expandedPath = $this->fs->expandPath($path);

        // Check if file exists
        if (!$this->fs->exists($expandedPath)) {
            return "SSH key file not found: {$path}";
        }

        // Read and validate key format
        try {
            $publicKey = $this->fs->readFile($expandedPath);
            $publicKey = trim((string) $publicKey);

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
                if (str_starts_with($publicKey, $prefix)) {
                    $isValid = true;
                    break;
                }
            }

            if (!$isValid) {
                // Explicit error for obsolete DSA keys
                if (str_starts_with($publicKey, 'ssh-dss')) {
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
}
