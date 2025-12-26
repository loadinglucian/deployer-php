<?php

declare(strict_types=1);

namespace DeployerPHP\Services;

use Symfony\Component\Process\Process;

/**
 * Service for executing local shell commands with consistent configuration.
 */
final readonly class ProcessService
{
    /**
     * Initialize the service with a filesystem utility used for directory validation and inspection.
     *
     * @param FilesystemService $fs Filesystem utility used to validate working directories and perform filesystem checks.
     */
    public function __construct(
        private FilesystemService $fs,
    ) {
    }

    /**
     * Execute the given command in the specified working directory and return the executed Process instance.
     *
     * @param list<string> $command The command and its arguments.
     * @param string $cwd The working directory in which to execute the command.
     * @param float $timeout Process timeout in seconds.
     * @return Process The Symfony Process instance after execution.
     * @throws \InvalidArgumentException If `$command` is empty or `$cwd` is not a directory.
     */
    public function run(array $command, string $cwd, float $timeout = 3.0): Process
    {
        if ($command === []) {
            throw new \InvalidArgumentException('Process command cannot be empty');
        }

        if (!$this->fs->isDirectory($cwd)) {
            throw new \InvalidArgumentException("Invalid working directory: {$cwd}");
        }

        $process = new Process($command, $cwd);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }
}
