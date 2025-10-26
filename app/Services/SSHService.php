<?php

declare(strict_types=1);

namespace Bigpixelrocket\DeployerPHP\Services;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * SSH and SFTP operations for remote server management.
 *
 * Provides connectivity testing, command execution, and file transfer capabilities.
 * All operations are stateless - connections are created and destroyed per operation.
 * Expects absolute paths to SSH keys (path resolution handled by callers).
 *
 * @example
 * // Test SSH connectivity (commands resolve key paths before calling)
 * $ssh->assertCanConnect('example.com', 22, 'deployer', '/home/user/.ssh/id_ed25519');
 *
 * // Execute single commands
 * $result = $ssh->executeCommand('example.com', 22, 'deployer', 'uptime', '/home/user/.ssh/id_ed25519');
 * echo $result['output'];     // "15:30:01 up 42 days, 3:14, 1 user..."
 * echo $result['exit_code'];  // 0
 *
 * // Execute bash scripts
 * $result = $ssh->executeScript('example.com', 22, 'deployer', './scripts/deploy.sh', '/home/user/.ssh/id_ed25519');
 * if ($result['exit_code'] === 0) {
 *     echo "Deployment successful";
 * }
 *
 * // Upload files to remote server
 * $ssh->uploadFile('example.com', 22, 'deployer', './local.txt', '/remote/path/file.txt', '/home/user/.ssh/id_ed25519');
 *
 * // Download files from remote server
 * $ssh->downloadFile('example.com', 22, 'deployer', '/remote/config.yml', './local-config.yml', '/home/user/.ssh/id_ed25519');
 */
class SSHService
{
    public function __construct(
        private readonly FilesystemService $fs,
    ) {
    }

    //
    // Public API
    // -------------------------------------------------------------------------------

    /**
     * Assert that SSH connection and authentication can be established.
     *
     * @throws \RuntimeException When connection or authentication fails
     */
    public function assertCanConnect(string $host, int $port, string $username, string $privateKeyPath): void
    {
        $ssh = $this->createConnection($host, $port, $username, $privateKeyPath);
        $this->disconnect($ssh);
    }

    /**
     * Execute a command on the remote server and return its output.
     *
     * @return array{output: string, exit_code: int}
     *
     * @throws \RuntimeException When connection, authentication, or command execution fails
     */
    public function executeCommand(string $host, int $port, string $username, string $command, string $privateKeyPath): array
    {
        $ssh = $this->createConnection($host, $port, $username, $privateKeyPath);

        try {
            $output = $ssh->exec($command);
            $exitCode = (int) $ssh->getExitStatus();

            return [
                'output' => is_string($output) ? $output : '',
                'exit_code' => $exitCode,
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error executing command on {$host}: " . $e->getMessage(), previous: $e);
        } finally {
            $this->disconnect($ssh);
        }
    }

    /**
     * Execute a local bash script file on the remote server.
     *
     * @return array{output: string, exit_code: int}
     *
     * @throws \RuntimeException When script file cannot be read or execution fails
     */
    public function executeScript(string $host, int $port, string $username, string $scriptPath, string $privateKeyPath): array
    {
        if (!$this->fs->exists($scriptPath)) {
            throw new \RuntimeException("Script file does not exist: {$scriptPath}");
        }

        $scriptContents = $this->fs->readFile($scriptPath);

        $ssh = $this->createConnection($host, $port, $username, $privateKeyPath);

        try {
            // Execute script contents through bash using heredoc
            $command = "bash <<'DEPLOYER_SCRIPT_EOF'\n{$scriptContents}\nDEPLOYER_SCRIPT_EOF";
            $output = $ssh->exec($command);
            $exitCode = (int) $ssh->getExitStatus();

            return [
                'output' => is_string($output) ? $output : '',
                'exit_code' => $exitCode,
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error executing script {$scriptPath} on {$host}: " . $e->getMessage(), previous: $e);
        } finally {
            $this->disconnect($ssh);
        }
    }

    /**
     * Upload a local file to the remote server via SFTP.
     *
     * @throws \RuntimeException When file operations fail
     */
    public function uploadFile(string $host, int $port, string $username, string $localPath, string $remotePath, string $privateKeyPath): void
    {
        if (!$this->fs->exists($localPath)) {
            throw new \RuntimeException("Local file does not exist: {$localPath}");
        }

        $sftp = $this->createSFTPConnection($host, $port, $username, $privateKeyPath);

        try {
            $contents = $this->fs->readFile($localPath);

            $uploaded = $sftp->put($remotePath, $contents);
            if (!$uploaded) {
                throw new \RuntimeException("Error uploading file to {$remotePath} on {$host}");
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error uploading file to {$remotePath} on {$host}: " . $e->getMessage(), previous: $e);
        } finally {
            $this->disconnect($sftp);
        }
    }

    /**
     * Download a remote file from the server via SFTP.
     *
     * @throws \RuntimeException When file operations fail
     */
    public function downloadFile(string $host, int $port, string $username, string $remotePath, string $localPath, string $privateKeyPath): void
    {
        $sftp = $this->createSFTPConnection($host, $port, $username, $privateKeyPath);

        try {
            $contents = $sftp->get($remotePath);
            if ($contents === false) {
                throw new \RuntimeException("Error downloading file from {$remotePath} on {$host}");
            }

            $this->fs->dumpFile($localPath, is_string($contents) ? $contents : '');
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error downloading file from {$remotePath} on {$host}: " . $e->getMessage());
        } finally {
            $this->disconnect($sftp);
        }
    }

    //
    // Connection Management
    // -------------------------------------------------------------------------------

    /**
     * Create and authenticate an SSH connection.
     *
     * @throws \RuntimeException When connection or authentication fails
     */
    private function createConnection(string $host, int $port, string $username, string $privateKeyPath): SSH2
    {
        $key = $this->loadPrivateKey($privateKeyPath);

        try {
            $ssh = new SSH2($host, $port);
            $loggedIn = $ssh->login($username, $key);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage());
        }

        if ($loggedIn !== true) {
            throw new \RuntimeException("SSH authentication failed for {$username}@{$host}. Check username and key permissions");
        }

        return $ssh;
    }

    /**
     * Create and authenticate an SFTP connection.
     *
     * @throws \RuntimeException When connection or authentication fails
     */
    private function createSFTPConnection(string $host, int $port, string $username, string $privateKeyPath): SFTP
    {
        $key = $this->loadPrivateKey($privateKeyPath);

        try {
            $sftp = new SFTP($host, $port);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error initiating SFTP connection to {$host}:{$port}: " . $e->getMessage());
        }

        try {
            $loggedIn = $sftp->login($username, $key);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error authenticating SFTP for {$username}@{$host}: " . $e->getMessage());
        }

        if ($loggedIn !== true) {
            throw new \RuntimeException("SFTP authentication failed for {$username}@{$host}. Check username and key permissions");
        }

        return $sftp;
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
    // -------------------------------------------------------------------------------

    /**
     * Load and validate private key from resolved path.
     *
     * @throws \RuntimeException When key cannot be found, read, or parsed
     */
    private function loadPrivateKey(string $privateKeyPath): PrivateKey
    {
        if (!$this->fs->exists($privateKeyPath)) {
            throw new \RuntimeException("SSH key does not exist: {$privateKeyPath}");
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
