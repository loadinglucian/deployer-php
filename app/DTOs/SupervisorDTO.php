<?php

declare(strict_types=1);

namespace DeployerPHP\DTOs;

readonly class SupervisorDTO
{
    /**
     * Create a SupervisorDTO containing supervisor program configuration.
     *
     * @param string $program      Program name for supervisor (unique identifier).
     * @param string $script       Script path within .deployer/supervisors/ (e.g., queue-worker.sh).
     * @param bool $autostart      Whether to start program at supervisord start.
     * @param bool $autorestart    Whether to restart program if it exits.
     * @param int $stopwaitsecs    Seconds to wait for program to stop before SIGKILL.
     * @param int $numprocs        Number of process instances to spawn.
     */
    public function __construct(
        public string $program,
        public string $script,
        public bool $autostart = true,
        public bool $autorestart = true,
        public int $stopwaitsecs = 3600,
        public int $numprocs = 1,
    ) {
    }
}
