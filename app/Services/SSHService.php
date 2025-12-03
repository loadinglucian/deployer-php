<?php

declare(strict_types=1);

namespace Deployer\Services;

use Deployer\DTOs\ServerDTO;
use Deployer\Exceptions\SSHTimeoutException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * SSH and SFTP operations for remote server management.
 *
 * Provides connectivity testing, command execution, and file transfer capabilities.
 * All operations are stateless - connections are created and destroyed per operation.
 * Expects absolute paths to SSH keys in ServerDTO (path resolution handled by callers).
 *
 * @example
 * // Test SSH connectivity
 * $server = new ServerDTO(name: 'web1', host: 'example.com', port: 22, username: 'deployer', privateKeyPath: '/home/user/.ssh/id_ed25519');
 * $ssh->assertCanConnect($server);
 *
 * // Execute commands
 * $result = $ssh->executeCommand($server, 'uptime');
 * echo $result['output'];     // "15:30:01 up 42 days, 3:14, 1 user..."
 * echo $result['exit_code'];  // 0
 *
 * // Upload files to remote server
 * $ssh->uploadFile($server, './local.txt', '/remote/path/file.txt');
 *
 * // Download files from remote server
 * $ssh->downloadFile($server, '/remote/config.yml', './local-config.yml');
 */
class SSHService
{
    public function __construct(
        private readonly FilesystemService $fs,
    ) {
    }

    //
    // Public API
    // ----

    /**
     * Assert that SSH connection and authentication can be established.
     *
     * @throws \RuntimeException When connection or authentication fails
     */
    public function assertCanConnect(ServerDTO $server): void
    {
        $ssh = $this->createConnection($server);
        $this->disconnect($ssh);
    }

    /**
     * Execute a command on the remote server and return its output.
     *
     * @param callable|null $outputCallback Optional callback for streaming output (receives string chunks)
     * @param int $timeout Timeout in seconds (default: 300 = 5 minutes)
     * @return array{output: string, exit_code: int}
     *
     * @throws SSHTimeoutException When command execution times out
     * @throws \RuntimeException When connection, authentication, or command execution fails
     */
    public function executeCommand(
        ServerDTO $server,
        string $command,
        ?callable $outputCallback = null,
        int $timeout = 300
    ): array {
        $ssh = $this->createConnection($server);

        try {
            // Always set a timeout to prevent infinite hangs
            $ssh->setTimeout($timeout);

            $output = $ssh->exec($command, $outputCallback);
            $exitCode = (int) $ssh->getExitStatus();

            // Check if command timed out (phpseclib returns false on timeout)
            if ($output === false) {
                throw new SSHTimeoutException(
                    "Command execution timed out after {$timeout} seconds on {$server->host}"
                );
            }

            return [
                'output' => is_string($output) ? $output : '',
                'exit_code' => $exitCode,
            ];
        } catch (SSHTimeoutException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error executing command on {$server->host}: " . $e->getMessage(), previous: $e);
        } finally {
            $this->disconnect($ssh);
        }
    }

    /**
     * Upload a local file to the remote server via SFTP.
     *
     * @throws \RuntimeException When file operations fail
     */
    public function uploadFile(
        ServerDTO $server,
        string $localPath,
        string $remotePath
    ): void {
        if (!$this->fs->exists($localPath)) {
            throw new \RuntimeException("Local file does not exist: {$localPath}");
        }

        $sftp = $this->createSFTPConnection($server);

        try {
            $contents = $this->fs->readFile($localPath);

            $uploaded = $sftp->put($remotePath, $contents);
            if (!$uploaded) {
                throw new \RuntimeException("Error uploading file to {$remotePath} on {$server->host}");
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error uploading file to {$remotePath} on {$server->host}: " . $e->getMessage(), previous: $e);
        } finally {
            $this->disconnect($sftp);
        }
    }

    /**
     * Download a remote file from the server via SFTP.
     *
     * @throws \RuntimeException When file operations fail
     */
    public function downloadFile(
        ServerDTO $server,
        string $remotePath,
        string $localPath
    ): void {
        $sftp = $this->createSFTPConnection($server);

        try {
            $contents = $sftp->get($remotePath);
            if ($contents === false) {
                throw new \RuntimeException("Error downloading file from {$remotePath} on {$server->host}");
            }

            $this->fs->dumpFile($localPath, is_string($contents) ? $contents : '');
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error downloading file from {$remotePath} on {$server->host}: " . $e->getMessage());
        } finally {
            $this->disconnect($sftp);
        }
    }

    //
    // Connection Management
    // ----

    /**
     * Execute an operation with retry logic and exponential backoff.
     *
     * @template T
     * @param callable(): T $attemptCallback Callback that attempts operation and returns on success or throws on failure
     * @param string $operationDescription Description for error messages (e.g., "connect to host")
     * @param int $retryAttempts Number of attempts (default: 5)
     * @param int $retryDelaySeconds Initial delay between attempts in seconds (default: 2, doubles each retry)
     *
     * @return T The successful operation result
     * @throws \RuntimeException When all attempts fail
     */
    private function withRetry(
        callable $attemptCallback,
        string $operationDescription,
        int $retryAttempts = 5,
        int $retryDelaySeconds = 2
    ): mixed {
        $attempt = 0;
        $delay = $retryDelaySeconds;
        $lastException = null;

        while ($attempt < $retryAttempts) {
            $attempt++;

            try {
                return $attemptCallback();
            } catch (\RuntimeException $e) {
                $lastException = $e;
            }

            // Don't sleep after the last failed attempt
            if ($attempt < $retryAttempts) {
                sleep($delay);
                $delay *= 2; // Exponential backoff
            }
        }

        // All attempts failed - loop guarantees $lastException is set (retryAttempts >= 1)
        /** @var \RuntimeException $lastException */
        if ($retryAttempts > 1) {
            throw new \RuntimeException(
                "Failed to {$operationDescription} after {$retryAttempts} attempts",
                previous: $lastException
            );
        }

        throw $lastException;
    }

    /**
     * Create and authenticate an SSH connection with retry logic.
     *
     * @throws \RuntimeException When connection or authentication fails after all retries
     */
    private function createConnection(ServerDTO $server): SSH2
    {
        if ($server->privateKeyPath === null) {
            throw new \RuntimeException("Server '{$server->name}' has no private SSH key configured");
        }

        $key = $this->loadPrivateKey($server->privateKeyPath);

        return $this->withRetry(
            attemptCallback: function () use ($server, $key) {
                try {
                    $ssh = new SSH2($server->host, $server->port);
                    $loggedIn = $ssh->login($server->username, $key);

                    if ($loggedIn === true) {
                        return $ssh;
                    }

                    throw new \RuntimeException(
                        "SSH authentication failed for {$server->username}@{$server->host}. Check username and key permissions"
                    );
                } catch (\RuntimeException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    throw new \RuntimeException(
                        "Failed to connect to {$server->host}:{$server->port}",
                        previous: $e
                    );
                }
            },
            operationDescription: "connect to {$server->host}"
        );
    }

    /**
     * Create and authenticate an SFTP connection with retry logic.
     *
     * @throws \RuntimeException When connection or authentication fails after all retries
     */
    private function createSFTPConnection(ServerDTO $server): SFTP
    {
        if ($server->privateKeyPath === null) {
            throw new \RuntimeException("Server '{$server->name}' has no private SSH key configured");
        }

        $key = $this->loadPrivateKey($server->privateKeyPath);

        return $this->withRetry(
            attemptCallback: function () use ($server, $key) {
                try {
                    $sftp = new SFTP($server->host, $server->port);
                    $loggedIn = $sftp->login($server->username, $key);

                    if ($loggedIn === true) {
                        return $sftp;
                    }

                    throw new \RuntimeException(
                        "SFTP authentication failed for {$server->username}@{$server->host}. Check username and key permissions"
                    );
                } catch (\RuntimeException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    throw new \RuntimeException(
                        "Failed to connect via SFTP to {$server->host}:{$server->port}",
                        previous: $e
                    );
                }
            },
            operationDescription: "connect via SFTP to {$server->host}"
        );
    }

    /**
     * Disconnect from remote server (best-effort, ignores errors).
     */
    private function disconnect(SSH2|SFTP $connection): void
    {
        try {
            $connection->disconnect();
        } catch (\Throwable) {
            // Ignore disconnect errors
        }
    }

    //
    // Private Key Management
    // ----

    /**
     * Load and validate private key from resolved path.
     *
     * @throws \RuntimeException When key cannot be found, read, or parsed
     */
    private function loadPrivateKey(string $privateKeyPath): PrivateKey
    {
        if (!$this->fs->exists($privateKeyPath)) {
            throw new \RuntimeException("Private SSH key does not exist: {$privateKeyPath}");
        }

        $keyContents = $this->fs->readFile($privateKeyPath);

        try {
            $key = PublicKeyLoader::load($keyContents);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error parsing SSH private key at {$privateKeyPath}: " . $e->getMessage());
        }

        if (!$key instanceof PrivateKey) {
            throw new \RuntimeException("File at {$privateKeyPath} is not a valid private key");
        }

        return $key;
    }
}
