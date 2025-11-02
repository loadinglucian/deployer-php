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
     * Playbooks write YAML output to a temp file (DEPLOYER_OUTPUT_FILE).
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

        // Generate unique output filename
        $outputFile = sprintf('/tmp/deployer-output-%d-%s.yml', time(), bin2hex(random_bytes(8)));

        // Build variable prefix with DEPLOYER_OUTPUT_FILE
        $varsPrefix = sprintf('DEPLOYER_OUTPUT_FILE=%s ', escapeshellarg($outputFile));
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

        // Display all output as progress messages
        $output = trim((string) $result['output']);
        if (!empty($output)) {
            $this->io->writeln(explode("\n", $output));
        }

        // Check exit code
        if ($result['exit_code'] !== 0) {
            $this->io->error('Playbook execution failed');

            return Command::FAILURE;
        }

        // Read YAML output from file and clean up
        try {
            $yamlResult = $this->io->promptSpin(
                callback: fn () => $this->ssh->executeCommand(
                    $server->host,
                    $server->port,
                    $server->username,
                    sprintf('cat %s 2>/dev/null && rm -f %s', escapeshellarg($outputFile), escapeshellarg($outputFile)),
                    $privateKeyPath
                ),
                message: $spinnerMessage
            );

            $yamlContent = trim((string) $yamlResult['output']);

            if (empty($yamlContent)) {
                throw new \RuntimeException('Something went wrong while trying to read ' . $outputFile);
            }
        } catch (\RuntimeException $e) {
            $this->io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Parse YAML
        try {
            $parsed = Yaml::parse($yamlContent);

            if (!is_array($parsed)) {
                throw new \RuntimeException('Unexpected format');
            }

            /** @var array<string, mixed> $parsed */
            return $parsed;
        } catch (\Throwable $e) {
            $this->io->error($e->getMessage());
            $this->io->writeln([
                '',
                '<fg=red>'.$yamlContent.'</>',
                '',
            ]);

            return Command::FAILURE;
        }
    }

}
