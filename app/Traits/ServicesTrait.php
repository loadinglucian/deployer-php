<?php

declare(strict_types=1);

namespace DeployerPHP\Traits;

/**
 * Centralized service display formatting.
 *
 * Maps process names (from ss/netstat) to consistent display labels.
 */
trait ServicesTrait
{
    /**
     * Canonical service labels (key = process name from ss/netstat).
     *
     * @var array<string, string>
     */
    private const SERVICE_LABELS = [
        'caddy' => 'Caddy',
        'mariadb' => 'MariaDB',
        'memcached' => 'Memcached',
        'mysqld' => 'MySQL',
        'postgres' => 'PostgreSQL',
        'redis-server' => 'Redis',
        'sshd' => 'SSH',
        'valkey-server' => 'Valkey',
    ];

    // ----
    // Helpers
    // ----

    /**
     * Get display label for a service/process.
     */
    protected function getServiceLabel(string $process): string
    {
        $key = strtolower($process);

        return self::SERVICE_LABELS[$key] ?? ucfirst($process);
    }

    /**
     * Format port with service label.
     */
    protected function formatPortService(int $port, string $process): string
    {
        return sprintf('%d (%s)', $port, $this->getServiceLabel($process));
    }
}
