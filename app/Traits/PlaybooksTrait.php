<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Traits;

use Bigpixelrocket\DeployerPHP\Container;
use Bigpixelrocket\DeployerPHP\DTOs\ServerDTO;
use Bigpixelrocket\DeployerPHP\Exceptions\SSHTimeoutException;
use Bigpixelrocket\DeployerPHP\Services\FilesystemService;
use Bigpixelrocket\DeployerPHP\Services\IOService;
use Bigpixelrocket\DeployerPHP\Services\SSHService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Reusable playbook things.
 *
 * Requires classes using this trait to have Container, IOService, SSHService, and FilesystemService properties.
 *
 * @property Container $container
 * @property FilesystemService $fs
 * @property IOService $io
 * @property SSHService $ssh
 */
trait PlaybooksTrait
{
    /**
     * Execute a playbook on a server.
     *
     * Handles SSH execution, error display, and YAML parsing.
     * Playbooks write YAML output to a temp file (DEPLOYER_OUTPUT_FILE).
     * Displays errors via IOService and returns Command::FAILURE on any error.
     *
     * Standard playbook environment variables:
     *   - DEPLOYER_OUTPUT_FILE: Output file path (provided automatically)
     *   - DEPLOYER_DISTRO: Exact distribution - caller must provide via $playbookVars
     *   - DEPLOYER_FAMILY: Distribution family - caller must provide via $playbookVars
     *   - DEPLOYER_PERMS: User permissions (root|sudo|none) - caller must provide via $playbookVars
     *
     * @param string $playbookName Playbook name without .sh extension (e.g., 'server-info', 'install-php', etc)
     * @param array<string, string> $playbookVars Playbook variables to pass to the playbook (don't pass sensitive data)
     * @param bool $streamOutput Stream output in real-time (true) or show spinner and display all at end (false)
     * @return array<string, mixed>|int Returns parsed YAML on success or Command::FAILURE on error
     */
    protected function executePlaybook(
        ServerDTO $server,
        string $playbookName,
        string $spinnerMessage,
        array $playbookVars = [],
        bool $streamOutput = false
    ): array|int {
        $projectRoot = dirname(__DIR__, 2);
        $playbookPath = $projectRoot . '/playbooks/' . $playbookName . '.sh';
        $scriptContents = $this->fs->readFile($playbookPath);

        // Unique output file name
        $outputFile = sprintf('/tmp/deployer-output-%d-%s.yml', time(), bin2hex(random_bytes(8)));

        // Override default vars with playbook vars
        $vars = [
            'DEPLOYER_OUTPUT_FILE' => $outputFile,
            ...$playbookVars,
        ];

        // Build var prefix string
        $varsPrefix = '';
        foreach ($vars as $key => $value) {
            $varsPrefix .= sprintf('%s=%s ', $key, escapeshellarg((string) $value));
        }

        // Wrap script with environment and heredoc
        $scriptWithVars = sprintf(
            "%sbash <<'DEPLOYER_SCRIPT_EOF'\n%s\nDEPLOYER_SCRIPT_EOF",
            $varsPrefix,
            $scriptContents
        );

        // Execute command
        try {
            if ($streamOutput) {
                // Streaming output in real-time
                $this->io->writeln([
                    '<fg=cyan>'.$spinnerMessage.'</>',
                    '',
                ]);

                $result = $this->ssh->executeCommand(
                    $server,
                    $scriptWithVars,
                    fn (string $chunk) => $this->io->write($chunk)
                );
            } else {
                // No streaming, use spinner and display output at end
                $result = $this->io->promptSpin(
                    callback: fn () => $this->ssh->executeCommand(
                        $server,
                        $scriptWithVars
                    ),
                    message: $spinnerMessage
                );

                $output = trim((string) $result['output']);
                if (!empty($output)) {
                    $this->io->writeln(explode("\n", $output));
                }
            }

            $this->io->writeln(''); // Empty line after output
        } catch (SSHTimeoutException $e) {
            $this->nay($e->getMessage());
            $this->io->writeln([
                '',
                '<fg=yellow>The process took longer than expected to complete.</>',
                '',
                '<fg=yellow>Package downloads or installation are taking longer than expected. Either:</>',
                '  • Server has a slow network connection',
                '  • Or the server is under heavy load',
                '',
                '<fg=yellow>You can try:</>',
                '  • Running the command again (operations are idempotent)',
                '  • Checking server load with <fg=cyan>server:info</>',
                '  • SSH into the server to check running processes',
                '',
            ]);

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        // Check exit code
        if ($result['exit_code'] !== 0) {
            $this->nay('Playbook execution failed');

            return Command::FAILURE;
        }

        // Read YAML output from file and clean up (quick operation, short timeout)
        try {
            $yamlResult = $this->io->promptSpin(
                callback: fn () => $this->ssh->executeCommand(
                    $server,
                    sprintf('cat %s 2>/dev/null && rm -f %s', escapeshellarg($outputFile), escapeshellarg($outputFile)),
                    null,
                    30
                ),
                message: $spinnerMessage
            );

            $yamlContent = trim((string) $yamlResult['output']);

            if (empty($yamlContent)) {
                throw new \RuntimeException('Something went wrong while trying to read ' . $outputFile);
            }
        } catch (\RuntimeException $e) {
            $this->nay($e->getMessage());

            return Command::FAILURE;
        }

        // Parse YAML
        try {
            $parsed = Yaml::parse($yamlContent);

            if (!is_array($parsed)) {
                throw new \RuntimeException('Unexpected format, expected YAML array in ' . $outputFile);
            }

            /** @var array<string, mixed> $parsed */
            return $parsed;
        } catch (\Throwable $e) {
            $this->nay($e->getMessage());
            $this->io->writeln([
                '<fg=red>'.$yamlContent.'</>',
                '',
            ]);

            return Command::FAILURE;
        }
    }

}
