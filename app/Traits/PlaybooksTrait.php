<?php

declare(strict_types=1);

namespace Deployer\Traits;

use Deployer\Container;
use Deployer\DTOs\ServerDTO;
use Deployer\Exceptions\SSHTimeoutException;
use Deployer\Services\FilesystemService;
use Deployer\Services\IOService;
use Deployer\Services\SSHService;
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
     * Execute a playbook on a server silently.
     *
     * @param ServerDTO $server
     * @param string $playbookName Playbook name without .sh extension (e.g., 'server-info', 'php-install', etc)
     * @param string $spinnerMessage Message to display while executing the playbook
     * @param array<string, string> $playbookVars Playbook variables to pass to the playbook (don't pass sensitive data)
     * @return array<string, mixed>|int Returns parsed YAML on success or Command::FAILURE on error
     */
    protected function executePlaybookSilently(
        ServerDTO $server,
        string $playbookName,
        string $spinnerMessage,
        array $playbookVars = [],
    ): array|int {
        $capture = '';

        return $this->executePlaybook(
            $server,
            $playbookName,
            $spinnerMessage,
            $playbookVars,
            $capture
        );
    }

    /**
     * Execute a playbook on a server.
     *
     * Handles SSH execution, error display, and YAML parsing.
     * Playbooks write YAML output to a temp file (DEPLOYER_OUTPUT_FILE).
     * Displays errors via IOService and returns Command::FAILURE on any error.
     *
     * Standard playbook environment variables:
     *   - DEPLOYER_OUTPUT_FILE: Output file path (provided automatically)
     *   - DEPLOYER_DISTRO: Exact distribution (ubuntu|debian) - caller must provide via $playbookVars
     *   - DEPLOYER_PERMS: User permissions (root|sudo|none) - caller must provide via $playbookVars
     *
     * @param ServerDTO $server
     * @param string $playbookName Playbook name without .sh extension (e.g., 'server-info', 'php-install', etc)
     * @param string $statusMessage Message to display while executing the playbook
     * @param array<string, string> $playbookVars Playbook variables to pass to the playbook (don't pass sensitive data)
     * @param string|null $capture Variable passed by reference to capture raw output. If null, output is streamed to console. If provided, output is captured silently.
     * @return array<string, mixed>|int Returns parsed YAML on success or Command::FAILURE on error
     */
    protected function executePlaybook(
        ServerDTO $server,
        string $playbookName,
        string $statusMessage,
        array $playbookVars = [],
        ?string &$capture = null
    ): array|int {

        //
        // Prepare playbook

        $projectRoot = dirname(__DIR__, 2);
        $playbookPath = $projectRoot . '/playbooks/' . $playbookName . '.sh';
        $scriptContents = $this->fs->readFile($playbookPath);

        // Prepend helpers.sh content to playbook for remote execution
        $helpersPath = $projectRoot . '/playbooks/helpers.sh';
        if (file_exists($helpersPath)) {
            $helpersContents = $this->fs->readFile($helpersPath);
            $scriptContents = $helpersContents . "\n\n" . $scriptContents;
        }

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

        //
        // Execution and output

        try {
            if (null === $capture) {
                // Streaming output in real time
                $this->out('$> ' . $statusMessage);

                $result = $this->ssh->executeCommand(
                    $server,
                    $scriptWithVars,
                    fn (string $chunk) => $this->io->write($chunk)
                );

                $this->out('───');
            } else {
                // No streaming, use spinner and capture output later
                $result = $this->io->promptSpin(
                    callback: fn () => $this->ssh->executeCommand(
                        $server,
                        $scriptWithVars
                    ),
                    message: $statusMessage
                );
            }

            // Display output when capturing only if there was an error
            if (null !== $capture && 0 !== $result['exit_code']) {
                $this->out('$>');
                $this->out(explode("\n", (string) $result['output']));
                $this->out('───');
            }

            $capture = trim((string) $result['output']);
        } catch (SSHTimeoutException $e) {
            $this->nay($e->getMessage());
            $this->out([
                '',
                '<fg=yellow>The process took longer than expected to complete. Either:</>',
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
            $this->nay('Execution failed');

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
                message: $statusMessage
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
            $this->out([
                '<fg=red>'.$yamlContent.'</>',
                '',
            ]);

            return Command::FAILURE;
        }
    }
}
