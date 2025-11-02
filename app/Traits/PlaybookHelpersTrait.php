<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\Container;
use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\Services\FilesystemService;
use Bigpixelrocket\DeployerPHP\Services\IOService;
use Bigpixelrocket\DeployerPHP\Services\SSHService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Reusable helpers for executing playbooks on remote servers.
 *
 * Requires classes using this trait to have Container, IOService, SSHService, and FilesystemService properties.
 *
 * @property Container $container
 * @property FilesystemService $fs
 * @property IOService $io
 * @property SSHService $ssh
 */
trait PlaybookHelpersTrait
{
    use KeyHelpersTrait;

    /**
     * Execute a playbook on a server.
     *
     * Handles SSH execution, error display, and YAML parsing.
     * Displays errors via IOService and returns Command::FAILURE on any error.
     *
     * @param string $playbookName Playbook name without .sh extension (e.g., 'server-info', 'install-php', etc)
     * @param array<string, string> $playbookVars Playbook variables to pass to the playbook (don't pass sensitive data)
     * @return array<string, mixed>|int Returns parsed YAML on success or Command::FAILURE on error
     */
    protected function executePlaybook(
        ServerDTO $server,
        string $playbookName,
        string $spinnerMessage,
        array $playbookVars = []
    ): array|int {
        $projectRoot = dirname(__DIR__, 2);
        $playbookPath = $projectRoot . '/playbooks/' . $playbookName . '.sh';
        $scriptContents = $this->fs->readFile($playbookPath);

        // Build variable prefix
        $varsPrefix = '';
        foreach ($playbookVars as $key => $value) {
            $varsPrefix .= sprintf('%s=%s ', $key, escapeshellarg((string) $value));
        }

        // Wrap script with environment and heredoc
        $scriptWithVars = sprintf(
            "%sbash <<'DEPLOYER_SCRIPT_EOF'\n%s\nDEPLOYER_SCRIPT_EOF",
            $varsPrefix,
            $scriptContents
        );

        // Resolve SSH key path
        $privateKeyPath = $this->resolvePrivateKeyPath($server->privateKeyPath);

        if ($privateKeyPath === null) {
            throw new \RuntimeException('No valid SSH private key found');
        }

        // Execute command
        try {
            $result = $this->io->promptSpin(
                callback: fn () => $this->ssh->executeCommand(
                    $server->host,
                    $server->port,
                    $server->username,
                    $scriptWithVars,
                    $privateKeyPath
                ),
                message: $spinnerMessage
            );
        } catch (\RuntimeException $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Check exit code
        if ($result['exit_code'] !== 0) {
            $this->io->error('Playbook execution failed:');
            $this->io->writeln([
                '',
                '<fg=red>'.$result['output'].'</>',
                '',
            ]);

            return Command::FAILURE;
        }

        // Parse YAML output
        try {
            $parsed = Yaml::parse($result['output']);

            if (!is_array($parsed)) {
                throw new \RuntimeException('Expected playbook output to be YAML array');
            }

            /** @var array<string, mixed> $parsed */
            return $parsed;
        } catch (\Throwable $e) {
            $this->io->error('Failed to parse YAML output: ' . $e->getMessage());
            $this->io->writeln([
                '',
                '<fg=yellow>Raw output:</>',
                '',
                $result['output'],
                '',
            ]);

            return Command::FAILURE;
        }
    }

}
