<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

/**
 * Shared PHP version helpers for PHP-FPM commands.
 */
trait PhpTrait
{
    /**
     * Extract installed PHP versions from server info.
     *
     * @param array<string, mixed> $info Server information
     *
     * @return list<string> List of version strings (e.g., ['8.3', '8.4'])
     */
    protected function getInstalledPhpVersions(array $info): array
    {
        /** @var array<string, mixed>|null $php */
        $php = $info['php'] ?? null;

        if (null === $php || !isset($php['versions'])) {
            return [];
        }

        /** @var array<int, array{version: string, extensions: list<string>}> $versions */
        $versions = $php['versions'];

        return array_values(array_map(
            fn (array $v): string => (string) $v['version'],
            $versions
        ));
    }

    /**
     * Validate PHP version exists on server.
     *
     * @param list<string> $installedVersions
     */
    protected function validatePhpVersionInput(mixed $version, array $installedVersions): ?string
    {
        if (!is_string($version)) {
            return 'PHP version must be a string';
        }

        if (!preg_match('/^\d+\.\d+$/', $version)) {
            return "Invalid PHP version format: '{$version}'. Expected format: 8.4";
        }

        if (!in_array($version, $installedVersions, true)) {
            $available = implode(', ', $installedVersions);

            return "PHP {$version} is not installed. Available versions: {$available}";
        }

        return null;
    }
}
