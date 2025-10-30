<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\Services\FilesystemService;
use Bigpixelrocket\DeployerPHP\Services\IOService;
use Symfony\Component\Console\Command\Command;

/**
 * Common SSH key helpers trait for commands.
 *
 * Requires classes using this trait to have FilesystemService and IOService properties.
 *
 * @property FilesystemService $fs
 * @property IOService $io
 */
trait KeyHelpersTrait
{
    /**
     * Display SSH key information in a consistent format.
     *
     * @param string $id Key ID (or name for AWS)
     * @param string $name Key name/description
     * @param string $fingerprint Key fingerprint
     */
    protected function displayKeyInfo(string $id, string $name, string $fingerprint): void
    {
        $this->io->writeln([
            "  ID:          <fg=gray>{$id}</>",
            "  Name:        <fg=gray>{$name}</>",
            "  Fingerprint: <fg=gray>{$fingerprint}</>",
            '',
        ]);
    }

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

    /**
     * Select a key from available keys via option or interactive prompt.
     *
     * @param array<int|string, string> $availableKeys Map of key IDs to descriptions
     * @param string $optionName Option name to check for pre-provided value
     * @param string $promptLabel Label for interactive prompt
     * @return array{key: string|null, exit_code: int} Selected key ID and exit code
     */
    protected function selectKey(array $availableKeys, string $optionName = 'key', string $promptLabel = 'Select SSH key:'): array
    {
        //
        // Check if keys are available

        if (count($availableKeys) === 0) {
            $this->io->warning('No SSH keys found');
            $this->io->writeln('');

            return ['key' => null, 'exit_code' => Command::SUCCESS];
        }

        //
        // Get key via option or prompt

        /** @var string $selectedKey */
        $selectedKey = $this->io->getOptionOrPrompt(
            $optionName,
            fn (): string => (string) $this->io->promptSelect(
                label: $promptLabel,
                options: $availableKeys
            )
        );

        //
        // Validate key exists in available keys

        if (!isset($availableKeys[$selectedKey])) {
            $this->io->error("SSH key '{$selectedKey}' not found");

            return ['key' => null, 'exit_code' => Command::FAILURE];
        }

        return ['key' => $selectedKey, 'exit_code' => Command::SUCCESS];
    }
}
