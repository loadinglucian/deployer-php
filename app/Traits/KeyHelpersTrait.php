<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\Services\IOService;
use Symfony\Component\Console\Command\Command;

/**
 * Reusable SSH key-related helpers for commands.
 *
 * Requires the using class to extend BaseCommand and have:
 * - protected IOService $io
 * - protected EnvService $env
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
     * Expand tilde (~) in file path to absolute home directory path.
     *
     * @throws \RuntimeException If HOME environment variable not found when needed
     */
    protected function expandKeyPath(string $path): string
    {
        if (!str_starts_with($path, '~')) {
            return $path;
        }

        $home = $this->env->get('HOME', required: false);
        if ($home === null) {
            throw new \RuntimeException('Could not determine home directory');
        }

        return $home . substr($path, 1);
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
