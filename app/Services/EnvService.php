<?php

declare(strict_types=1);

namespace Deployer\Services;

use Symfony\Component\Dotenv\Dotenv;

/**
 * Environment variable reader (first checks .env file then system environment variables)
 */
class EnvService
{
    /** @var array<string, string> */
    private array $dotenv = [];

    private ?string $envPath = null;

    private string $envFileStatus = '';

    public function __construct(
        private readonly FilesystemService $fs,
        private readonly Dotenv $dotenvParser,
    ) {
    }

    //
    // Public
    // ----

    /**
     * Get first non-empty value for given key(s).
     *
     * @param array<int, string>|string $keys
     */
    public function get(array|string $keys, bool $required = true): ?string
    {
        $keysList = is_array($keys) ? $keys : [$keys];

        foreach ($keysList as $key) {
            // Check .env file first
            if (isset($this->dotenv[$key]) && $this->dotenv[$key] !== '') {
                return $this->dotenv[$key];
            }

            // Check environment variables second
            $value = $_ENV[$key] ?? getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if ($required) {
            $list = implode(', ', $keysList);
            $label = count($keysList) > 1 ? 'variables' : 'variable';
            throw new \InvalidArgumentException("Missing required environment {$label}: {$list}");
        }

        return null;
    }

    /**
     * Set a custom .env path.
     */
    public function setCustomPath(?string $path): void
    {
        $this->envPath = $path;
    }

    /**
     * Load and parse .env file if it exists.
     */
    public function loadEnvFile(): void
    {
        $this->dotenv = [];

        $path = $this->getEnvPath();

        if (!$this->fs->exists($path)) {
            $this->envFileStatus = "No .env file found at {$path}";
            return;
        }

        $this->readDotenv();

        $this->envFileStatus = $path;
        if (!count($this->dotenv)) {
            $this->envFileStatus = "No variables found in {$path}";
        }
    }

    /**
     * Get the status of the .env file.
     */
    public function getEnvFileStatus(): string
    {
        return $this->envFileStatus;
    }

    //
    // Private
    // ----

    /**
     * Get the resolved .env path (custom or default).
     */
    private function getEnvPath(): string
    {
        return $this->envPath ?? rtrim($this->fs->getCwd(), '/') . '/.env';
    }

    /**
     * Read .env file into internal array.
     *
     * @throws \RuntimeException If file cannot be read or parsed
     */
    private function readDotenv(): void
    {
        $path = $this->getEnvPath();

        try {
            $content = $this->fs->readFile($path);
            $parsed = $this->dotenvParser->parse($content, $path);

            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $this->dotenv[$k] = $v;
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error reading .env file from {$path}: " . $e->getMessage());
        }
    }
}
